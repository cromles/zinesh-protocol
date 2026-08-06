import assert from 'node:assert/strict';

const APP = 'https://app.zinesh.com';
const API = 'https://www.zinesh.com';

async function corsPreflight(path) {
  const res = await fetch(`${API}${path}`, {
    method: 'OPTIONS',
    headers: {
      Origin: APP,
      'Access-Control-Request-Method': 'POST',
      'Access-Control-Request-Headers': 'content-type',
    },
  });
  assert.equal(res.status, 204, `${path} preflight`);
  assert.equal(res.headers.get('access-control-allow-origin'), APP, `${path} allow-origin`);
}

async function postJson(path, body) {
  const res = await fetch(`${API}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Origin: APP },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  return { status: res.status, data, acao: res.headers.get('access-control-allow-origin') };
}

const home = await fetch(`${APP}/`);
assert.equal(home.status, 200, 'app home');
const html = await home.text();
assert.match(html, /main-[A-Za-z0-9_-]+\.js/, 'app bundle');

await corsPreflight('/api/auth.php');
await corsPreflight('/api/wallet.php');
await corsPreflight('/api/payment.php');

const oauth = await postJson('/api/auth.php', { action: 'oauth_config' });
assert.equal(oauth.status, 200, 'oauth_config status');
assert.equal(oauth.acao, APP, 'oauth_config cors');
assert.equal(oauth.data?.google?.enabled, true, 'google oauth enabled');

const session = await postJson('/api/auth.php', { action: 'session', sessionToken: 'invalid' });
assert.equal(session.status, 401, 'invalid session');

const payment = await fetch(`${API}/api/payment.php?action=status`, { headers: { Origin: APP } });
const paymentData = await payment.json();
assert.equal(payment.status, 200, 'payment status');
assert.equal(payment.headers.get('access-control-allow-origin'), APP, 'payment cors');
assert.equal(typeof paymentData.paymentEnabled, 'boolean', 'payment payload');

console.log('app smoke test: PASS');
console.log(`bundle: ${html.match(/main-[A-Za-z0-9_-]+\.js/)?.[0]}`);
