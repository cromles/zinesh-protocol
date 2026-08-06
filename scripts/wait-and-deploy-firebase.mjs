/**
 * Firebase oturumu açıldıktan sonra setup + deploy + auth domain.
 * Tek Hosting sitesi (decisive-patrol-dszp9) kullanılır — ikinci site yetkisi yok.
 * Kullanım: node scripts/wait-and-deploy-firebase.mjs
 */
import { existsSync, readFileSync, copyFileSync, mkdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const PROJECT = 'decisive-patrol-dszp9';
const SITE = 'decisive-patrol-dszp9';
const FIREBASE_CLI_CLIENT_ID =
  '563584335869-fgrhgmd47bqnekij5i8b5pr7ho1dfdk.apps.googleusercontent.com';
const FIREBASE_CLI_CLIENT_SECRET = 'j9pVJFWg5KGUFYo4FYFYqoie';
const DOMAINS_TO_ADD = ['zinesh.com', 'www.zinesh.com', 'app.zinesh.com'];

function readRefreshToken() {
  const p = join(homedir(), '.config', 'configstore', 'firebase-tools.json');
  if (!existsSync(p)) return null;
  try {
    return JSON.parse(readFileSync(p, 'utf8'))?.tokens?.refresh_token || null;
  } catch {
    return null;
  }
}

function run(label, cmd, args) {
  console.log(`\n→ ${label}`);
  const res = spawnSync(cmd, args, { stdio: 'inherit', shell: true, cwd: ROOT });
  if (res.status !== 0) throw new Error(`${label} failed (${res.status})`);
}

function copyBridgeIntoPublic() {
  const src = join(ROOT, 'firebase-hosting-bridge', 'google-auth.html');
  const dest = join(ROOT, 'public', 'google-auth.html');
  mkdirSync(dirname(dest), { recursive: true });
  copyFileSync(src, dest);
  console.log('Copied google-auth.html → public/');
}

async function getAccessToken(refreshToken) {
  const res = await fetch('https://oauth2.googleapis.com/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'refresh_token',
      refresh_token: refreshToken,
      client_id: FIREBASE_CLI_CLIENT_ID,
      client_secret: FIREBASE_CLI_CLIENT_SECRET,
    }),
  });
  const data = await res.json();
  if (!data.access_token) throw new Error(data.error_description || data.error || 'token failed');
  return data.access_token;
}

async function ensureAuthorizedDomains(accessToken) {
  const base = `https://identitytoolkit.googleapis.com/admin/v2/projects/${PROJECT}/config`;
  const headers = {
    Authorization: `Bearer ${accessToken}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };
  const getRes = await fetch(base, { headers });
  if (!getRes.ok) throw new Error(`config GET ${getRes.status}`);
  const current = await getRes.json();
  const existing = Array.isArray(current.authorizedDomains) ? current.authorizedDomains : [];
  const merged = [...existing];
  for (const d of DOMAINS_TO_ADD) {
    if (!merged.includes(d)) merged.push(d);
  }
  if (merged.length === existing.length) {
    console.log('Authorized domains already OK');
    return;
  }
  const patchRes = await fetch(`${base}?updateMask=authorizedDomains`, {
    method: 'PATCH',
    headers,
    body: JSON.stringify({ authorizedDomains: merged }),
  });
  if (!patchRes.ok) throw new Error(`config PATCH ${patchRes.status}: ${(await patchRes.text()).slice(0, 200)}`);
  console.log('Authorized domains updated:', DOMAINS_TO_ADD.join(', '));
}

async function main() {
  const refresh = readRefreshToken();
  if (!refresh) {
    console.error('Firebase oturumu yok. scripts\\finish-firebase-app.bat çalıştırıp Google ile giriş yap.');
    process.exit(2);
  }

  console.log('Firebase oturumu bulundu — tek siteye deploy:', SITE);
  copyBridgeIntoPublic();
  run('use project', 'npx.cmd', ['firebase-tools', 'use', PROJECT]);
  run('target app', 'npx.cmd', [
    'firebase-tools',
    'target:apply',
    'hosting',
    'app',
    SITE,
    '--project',
    PROJECT,
  ]);
  run('build + deploy hosting:app', 'npm.cmd', ['run', 'deploy:firebase:app']);

  const token = await getAccessToken(refresh);
  await ensureAuthorizedDomains(token);

  console.log('\nDONE');
  console.log(`Hosting: https://${SITE}.web.app`);
  console.log('Custom domain: Firebase Console → Hosting → Add custom domain → app.zinesh.com');
  console.log('Cloudflare DNS (DNS only): app CNAME → ' + SITE + '.web.app');
}

main().catch((err) => {
  console.error(err.message || err);
  process.exit(1);
});
