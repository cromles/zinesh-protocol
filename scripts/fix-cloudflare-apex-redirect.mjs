/**
 * zinesh.com (apex) → www.zinesh.com kalıcı yönlendirme.
 */
const DOMAIN = 'zinesh.com';
const TOKEN = process.env.CLOUDFLARE_API_TOKEN || process.env.CF_API_TOKEN;

if (!TOKEN) {
  console.error('CLOUDFLARE_API_TOKEN gerekli.');
  process.exit(1);
}

const API = 'https://api.cloudflare.com/client/v4';

async function cf(path, init = {}) {
  const res = await fetch(`${API}${path}`, {
    ...init,
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      'Content-Type': 'application/json',
      ...(init.headers || {}),
    },
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.success === false) {
    const msg = data.errors?.map((e) => e.message).join('; ') || res.statusText;
    const err = new Error(`${path}: ${msg}`);
    err.status = res.status;
    throw err;
  }
  return data;
}

async function getZoneId() {
  const { result } = await cf(`/zones?name=${DOMAIN}`);
  const zone = result?.[0];
  if (!zone?.id) throw new Error(`Zone bulunamadı: ${DOMAIN}`);
  return zone.id;
}

async function getOrCreateRedirectRuleset(zoneId) {
  const phase = 'http_request_dynamic_redirect';
  const entry = await cf(`/zones/${zoneId}/rulesets/phases/${phase}/entrypoint`);
  if (entry?.result?.id) return entry.result;

  const created = await cf(`/zones/${zoneId}/rulesets`, {
    method: 'POST',
    body: JSON.stringify({
      name: 'Zinesh apex redirect',
      kind: 'zone',
      phase,
      rules: [],
    }),
  });
  return created.result;
}

function apexRuleExists(ruleset) {
  return (ruleset.rules || []).some(
    (r) =>
      r.enabled !== false &&
      typeof r.expression === 'string' &&
      r.expression.includes('zinesh.com') &&
      r.action === 'redirect',
  );
}

function buildApexRedirectRule() {
  return {
    description: 'zinesh.com → www.zinesh.com (path+query korunur)',
    expression: '(http.host eq "zinesh.com")',
    action: 'redirect',
    action_parameters: {
      from_value: {
        status_code: 301,
        preserve_query_string: true,
        target_url: {
          // http.request.uri = path + ?query — /api/auth.php kaybolmasın
          expression: 'concat("https://www.zinesh.com", http.request.uri)',
        },
      },
    },
    enabled: true,
  };
}

async function addApexRulesetRule(zoneId, ruleset) {
  const existingRules = Array.isArray(ruleset.rules) ? [...ruleset.rules] : [];
  const apexIdx = existingRules.findIndex(
    (r) =>
      typeof r.expression === 'string' &&
      r.expression.includes('zinesh.com') &&
      r.action === 'redirect',
  );

  const nextRule = buildApexRedirectRule();
  if (apexIdx >= 0) {
    const prev = existingRules[apexIdx];
    const prevExpr =
      prev?.action_parameters?.from_value?.target_url?.expression ||
      prev?.action_parameters?.from_value?.target_url?.value ||
      '';
    if (String(prevExpr).includes('http.request.uri') && !String(prevExpr).endsWith('.path)')) {
      console.log('✓ Redirect Rules kuralı path koruyor — güncelleme yok.');
      return ruleset;
    }
    existingRules[apexIdx] = { ...prev, ...nextRule, id: prev.id };
    console.log('→ Redirect Rules kuralı path koruyacak şekilde güncelleniyor...');
  } else {
    existingRules.push(nextRule);
    console.log('→ Redirect Rules kuralı ekleniyor...');
  }

  const updated = await cf(`/zones/${zoneId}/rulesets/${ruleset.id}`, {
    method: 'PUT',
    body: JSON.stringify({
      name: ruleset.name,
      description: ruleset.description,
      rules: existingRules,
    }),
  });
  return updated.result;
}

async function addApexPageRule(zoneId) {
  const { result: existing } = await cf(`/zones/${zoneId}/pagerules`);
  const hasRule = (existing || []).some(
    (r) =>
      r.status === 'active' &&
      r.actions?.some(
        (a) =>
          a.id === 'forwarding_url' &&
          String(a.value?.url || '').includes('www.zinesh.com'),
      ),
  );
  if (hasRule) {
    console.log('✓ Page Rule zaten var.');
    return;
  }

  await cf(`/zones/${zoneId}/pagerules`, {
    method: 'POST',
    body: JSON.stringify({
      targets: [
        {
          target: 'url',
          constraint: { operator: 'matches', value: `${DOMAIN}/*` },
        },
      ],
      actions: [
        {
          id: 'forwarding_url',
          value: { url: 'https://www.zinesh.com/$1', status_code: 301 },
        },
      ],
      priority: 1,
      status: 'active',
    }),
  });
  console.log('✓ Page Rule oluşturuldu.');
}

async function verify() {
  const tests = [
    ['https://zinesh.com/', 'https://www.zinesh.com/'],
    ['https://zinesh.com/nedir/', 'https://www.zinesh.com/nedir/'],
  ];
  for (const [from, expectedPrefix] of tests) {
    const res = await fetch(from, { redirect: 'manual' });
    const loc = res.headers.get('location') || '';
    const ok = res.status >= 301 && res.status <= 308 && loc.startsWith(expectedPrefix);
    console.log(ok ? '✓' : '✗', `${from} → ${res.status} ${loc || '(yok)'}`);
  }
}

async function main() {
  console.log(`Cloudflare apex düzeltmesi: ${DOMAIN}`);
  const zoneId = await getZoneId();
  console.log(`Zone ID: ${zoneId}`);

  try {
    const ruleset = await getOrCreateRedirectRuleset(zoneId);
    await addApexRulesetRule(zoneId, ruleset);
    console.log('✓ Redirect Rules ile uygulandı.');
  } catch (err) {
    const authFail =
      err.message.includes('Authentication error') || err.message.includes('Unauthorized');
    if (!authFail) throw err;
    console.log('Redirect Rules izni yok — Page Rule deneniyor...');
    await addApexPageRule(zoneId);
  }

  console.log('Doğrulama...');
  await verify();
}

main().catch((err) => {
  console.error('Hata:', err.message);
  console.error('Token izinleri: Zone Read + Zone Rulesets Edit VEYA Zone Page Rules Edit');
  process.exit(1);
});
