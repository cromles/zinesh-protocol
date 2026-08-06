/**
 * Canlı site kontrol listesi — deploy ÖNCESİ çalıştırın.
 * Kullanım: node scripts/verify-before-deploy.mjs
 * Deploy etmez; yalnızca okuma/test istekleri atar.
 */
const BASE = process.env.ZINESH_BASE || 'https://www.zinesh.com';

async function post(path, body, timeoutMs = 20000) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    const res = await fetch(`${BASE}${path}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      signal: ctrl.signal,
    });
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, data, ms: 0 };
  } finally {
    clearTimeout(t);
  }
}

const checks = [];

function pass(name, detail) {
  checks.push({ name, ok: true, detail });
  console.log(`✓ ${name} — ${detail}`);
}

function fail(name, detail) {
  checks.push({ name, ok: false, detail });
  console.log(`✗ ${name} — ${detail}`);
}

const t0 = Date.now();
const html = await fetch(`${BASE}/index.html`).then((r) => r.text());
const jsMatch =
  html.match(/\/assets\/(?:main|index)-([A-Za-z0-9_-]+)\.js/) ||
  html.match(/src="(\/assets\/[^"]+\.js)"/);
if (jsMatch) pass('index.html bundle', jsMatch[0].replace(/^src="/, '').replace(/"$/, ''));
else fail('index.html bundle', 'JS hash bulunamadı (main-*.js / index-*.js)');

const apexRes = await fetch('https://zinesh.com/', { redirect: 'manual' });
const apexLoc = apexRes.headers.get('location') || '';
if (apexRes.status >= 301 && apexRes.status <= 308 && apexLoc.startsWith('https://www.zinesh.com')) {
  pass('apex redirect', `${apexRes.status} → ${apexLoc}`);
} else if (apexRes.status === 404) {
  fail('apex redirect', 'zinesh.com hâlâ 404/Shopify — npm run fix:apex veya fix-apex.bat çalıştır');
} else {
  fail('apex redirect', `HTTP ${apexRes.status} location=${apexLoc || 'yok'}`);
}

const t1 = Date.now();
const oauthCfg = await post('/api/auth.php', { action: 'oauth_config' });
if (oauthCfg.ok && oauthCfg.data?.google?.enabled) {
  pass('oauth_config', `mode=${oauthCfg.data.google.mode || 'gis'} (${Date.now() - t1}ms)`);
} else {
  fail('oauth_config', `HTTP ${oauthCfg.status} ${JSON.stringify(oauthCfg.data).slice(0, 120)}`);
}

const t1b = Date.now();
const oauthAccess = await post('/api/auth.php', { action: 'oauth_google_access', accessToken: 'invalid-probe' });
if (oauthAccess.status === 400 || oauthAccess.status === 401) {
  pass('oauth_google_access', `${oauthAccess.status} beklenen (${Date.now() - t1b}ms)`);
} else {
  fail('oauth_google_access', `HTTP ${oauthAccess.status} ${JSON.stringify(oauthAccess.data).slice(0, 120)}`);
}

const t2 = Date.now();
const sessionProbe = await post('/api/auth.php', { action: 'session', sessionToken: 'invalid' });
if (sessionProbe.status === 401) {
  pass('session endpoint', `401 beklenen (${Date.now() - t2}ms)`);
} else if (sessionProbe.status === 200) {
  fail('session endpoint', 'Geçersiz token ile 200 döndü');
} else {
  fail('session endpoint', `HTTP ${sessionProbe.status} (${Date.now() - t2}ms)`);
}

const t3 = Date.now();
const wallet = await post('/api/wallet.php', { action: 'state', sessionToken: 'invalid' });
if (wallet.status === 401) {
  pass('wallet.php POST', `401 beklenen (${Date.now() - t3}ms)`);
} else if (wallet.status === 405) {
  fail('wallet.php POST', '405 — nginx proxy eksik');
} else {
  fail('wallet.php POST', `HTTP ${wallet.status}`);
}

const t4 = Date.now();
const payment = await post('/api/payment.php', { action: 'status' });
if (payment.ok && typeof payment.data?.paymentEnabled === 'boolean') {
  pass('payment.php status', `enabled=${payment.data.paymentEnabled} havale=${payment.data.havaleEnabled} (${Date.now() - t4}ms)`);
} else {
  fail('payment.php status', `HTTP ${payment.status} ${JSON.stringify(payment.data).slice(0, 120)}`);
}

console.log('\n--- Özet ---');
const failed = checks.filter((c) => !c.ok);
if (failed.length === 0) {
  console.log(`Tüm kontroller geçti (${Date.now() - t0}ms). Deploy için: node deploy-full.mjs`);
  process.exit(0);
}
console.log(`${failed.length} kontrol başarısız. Deploy etmeden önce düzeltin.`);
process.exit(1);
