/**
 * Cloudflare: Googlebot ve diğer arama motoru botlarının 403 challenge almaması.
 * - Bot Fight Mode / Under Attack kapat
 * - Güvenlik seviyesini düşür
 * - Doğrulanmış botlar ve bilinen crawler UA'ları için WAF skip kuralı
 *
 * Kullanım: CLOUDFLARE_API_TOKEN=... node scripts/fix-cloudflare-seo-crawlers.mjs
 */
const DOMAIN = 'zinesh.com';
const TOKEN = process.env.CLOUDFLARE_API_TOKEN || process.env.CF_API_TOKEN;

if (!TOKEN) {
  console.error('CLOUDFLARE_API_TOKEN veya CF_API_TOKEN gerekli.');
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

async function patchSetting(zoneId, setting, value) {
  try {
    await cf(`/zones/${zoneId}/settings/${setting}`, {
      method: 'PATCH',
      body: JSON.stringify({ value }),
    });
    console.log(`✓ ${setting} = ${value}`);
    return true;
  } catch (err) {
    console.warn(`⚠ ${setting}: ${err.message}`);
    return false;
  }
}

async function getOrCreateFirewallRuleset(zoneId) {
  const phase = 'http_request_firewall_custom';
  const entry = await cf(`/zones/${zoneId}/rulesets/phases/${phase}/entrypoint`);
  if (entry?.result?.id) return entry.result;

  const created = await cf(`/zones/${zoneId}/rulesets`, {
    method: 'POST',
    body: JSON.stringify({
      name: 'Zinesh SEO crawler bypass',
      kind: 'zone',
      phase,
      rules: [],
    }),
  });
  return created.result;
}

const CRAWLER_RULE_DESC = 'Allow verified bots and search crawlers (SEO)';
const CRAWLER_EXPRESSION =
  '(cf.client.bot) or (http.user_agent contains "Googlebot") or (http.user_agent contains "Google-InspectionTool") or (http.user_agent contains "AdsBot-Google") or (http.user_agent contains "bingbot") or (http.user_agent contains "DuckDuckBot") or (http.user_agent contains "YandexBot") or (http.user_agent contains "Baiduspider")';

function crawlerRuleExists(ruleset) {
  return (ruleset.rules || []).some(
    (r) =>
      r.enabled !== false &&
      r.action === 'skip' &&
      (r.description === CRAWLER_RULE_DESC ||
        (typeof r.expression === 'string' && r.expression.includes('Googlebot'))),
  );
}

async function ensureCrawlerSkipRule(zoneId) {
  const ruleset = await getOrCreateFirewallRuleset(zoneId);
  if (crawlerRuleExists(ruleset)) {
    console.log('✓ WAF crawler skip rule zaten var');
    return;
  }

  const rules = [
    ...(ruleset.rules || []),
    {
      description: CRAWLER_RULE_DESC,
      expression: CRAWLER_EXPRESSION,
      action: 'skip',
      enabled: true,
    },
  ];

  await cf(`/zones/${zoneId}/rulesets/${ruleset.id}`, {
    method: 'PUT',
    body: JSON.stringify({
      name: ruleset.name,
      description: ruleset.description ?? 'Zinesh firewall',
      kind: ruleset.kind,
      phase: ruleset.phase,
      rules,
    }),
  });
  console.log('✓ WAF crawler skip rule eklendi');
}

async function main() {
  const zoneId = await getZoneId();
  console.log(`Zone: ${DOMAIN} (${zoneId})`);

  await patchSetting(zoneId, 'security_level', 'low');
  await patchSetting(zoneId, 'browser_check', 'off');
  await patchSetting(zoneId, 'bot_fight_mode', 'off');
  await patchSetting(zoneId, 'challenge_ttl', 300);
  await ensureCrawlerSkipRule(zoneId);

  console.log('\nCloudflare SEO/crawler ayarları güncellendi.');
  console.log('Not: Değişikliklerin yayılması birkaç dakika sürebilir.');
}

main().catch((err) => {
  console.error(err.message || err);
  process.exit(1);
});
