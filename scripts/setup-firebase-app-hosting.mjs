/**
 * app.zinesh.com için Firebase Hosting target kurulumu (tek seferlik).
 * Not: Ayrı app-zinesh sitesi yerine mevcut decisive-patrol-dszp9 sitesi kullanılır
 * (ikinci site create 403 verebiliyor).
 */
import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';

const PROJECT = 'decisive-patrol-dszp9';
const SITE = 'decisive-patrol-dszp9';

function run(cmd, args) {
  const res = spawnSync(cmd, args, { stdio: 'inherit', shell: process.platform === 'win32' });
  if (res.status !== 0) process.exit(res.status ?? 1);
}

function isLoggedIn() {
  const configPath = join(homedir(), '.config', 'configstore', 'firebase-tools.json');
  if (!existsSync(configPath)) return false;
  try {
    return Boolean(JSON.parse(readFileSync(configPath, 'utf8'))?.tokens?.refresh_token);
  } catch {
    return false;
  }
}

console.log('Firebase app hosting kurulumu');
console.log(`Proje/Site: ${PROJECT}`);

if (!isLoggedIn()) {
  console.log('Firebase oturumu yok — tarayıcıda giriş isteniyor…');
  run('npx', ['firebase-tools', 'login']);
} else {
  console.log('Firebase oturumu mevcut.');
}

run('npx', ['firebase-tools', 'use', PROJECT]);
run('npx', ['firebase-tools', 'target:apply', 'hosting', 'app', SITE, '--project', PROJECT]);

console.log('\nSonraki:');
console.log('1) npm run deploy:firebase:app');
console.log('2) Firebase Console → Hosting → Custom domain → app.zinesh.com');
console.log(`3) Cloudflare DNS: app CNAME → ${SITE}.web.app (DNS only / gri bulut)`);
console.log('4) Auth: npm run google:auth-setup');
