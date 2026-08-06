/**
 * Tek seferlik Google giriş kurulumu:
 * 1) Firebase CLI ile giriş (tarayıcı açılır)
 * 2) zinesh.com / www.zinesh.com yetkili alan adlarına eklenir
 * 3) Köprü sayfası Firebase Hosting'e yüklenir
 *
 * Çalıştır: npm run google:auth-setup
 */
import { spawnSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';

const project = 'decisive-patrol-dszp9';
const DOMAINS_TO_ADD = ['zinesh.com', 'www.zinesh.com', 'app.zinesh.com'];

const FIREBASE_CLI_CLIENT_ID =
  '563584335869-fgrhgmd47bqnekij5i8b5pr7ho1dfdk.apps.googleusercontent.com';
const FIREBASE_CLI_CLIENT_SECRET = 'j9pVJFWg5KGUFYo4FYFYqoie';

function runFirebase(args, label) {
  console.log(`\n→ ${label}…`);
  const result = spawnSync('npx', ['firebase-tools@14.4.0', ...args], {
    stdio: 'inherit',
    shell: true,
  });
  if (result.status !== 0) {
    console.error(`\n${label} başarısız.\n`);
    process.exit(result.status ?? 1);
  }
}

function readRefreshToken() {
  const configPath = join(homedir(), '.config', 'configstore', 'firebase-tools.json');
  if (!existsSync(configPath)) return null;
  try {
    const cfg = JSON.parse(readFileSync(configPath, 'utf8'));
    return cfg?.tokens?.refresh_token ?? null;
  } catch {
    return null;
  }
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
  if (!data.access_token) {
    throw new Error(data.error_description || data.error || 'Access token alınamadı');
  }
  return data.access_token;
}

async function addAuthorizedDomains(accessToken) {
  const base = `https://identitytoolkit.googleapis.com/admin/v2/projects/${project}/config`;
  const headers = {
    Authorization: `Bearer ${accessToken}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };

  const getRes = await fetch(base, { headers });
  if (!getRes.ok) {
    const err = await getRes.text();
    throw new Error(`Firebase config okunamadı (${getRes.status}): ${err.slice(0, 200)}`);
  }
  const current = await getRes.json();
  const existing = Array.isArray(current.authorizedDomains) ? current.authorizedDomains : [];
  const merged = [...existing];
  for (const d of DOMAINS_TO_ADD) {
    if (!merged.includes(d)) merged.push(d);
  }
  if (merged.length === existing.length) {
    console.log('Yetkili alan adları zaten güncel:', DOMAINS_TO_ADD.join(', '));
    return;
  }

  const patchRes = await fetch(`${base}?updateMask=authorizedDomains`, {
    method: 'PATCH',
    headers,
    body: JSON.stringify({ authorizedDomains: merged }),
  });
  if (!patchRes.ok) {
    const err = await patchRes.text();
    throw new Error(`Alan adları eklenemedi (${patchRes.status}): ${err.slice(0, 300)}`);
  }
  console.log('Yetkili alan adları eklendi:', DOMAINS_TO_ADD.join(', '));
}

console.log('\n=== Zinesh Google giriş kurulumu ===\n');
console.log('Tarayıcı açılacak — Firebase projesine erişen Google hesabınla giriş yap.\n');

runFirebase(['login'], 'Firebase giriş');

const refresh = readRefreshToken();
if (refresh) {
  try {
    console.log('\n→ Yetkili alan adları güncelleniyor…');
    const token = await getAccessToken(refresh);
    await addAuthorizedDomains(token);
  } catch (err) {
    console.warn('\nUyarı: Alan adları otomatik eklenemedi:', err.message);
    console.warn('Manuel: Firebase Console → Authentication → Settings → Authorized domains\n');
  }
} else {
  console.warn('\nFirebase refresh token bulunamadı; alan adları atlandı.\n');
}

runFirebase(['deploy', '--only', 'hosting', '--project', project], 'Hosting deploy');

console.log('\n=== Tamam ===');
console.log('Köprü: https://decisive-patrol-dszp9.firebaseapp.com/google-auth.html');
console.log('Firebase Console → Authentication → Sign-in method → Google → Enable (kapalıysa)\n');
