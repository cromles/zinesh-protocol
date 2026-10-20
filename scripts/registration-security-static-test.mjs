import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const read = (path) => readFileSync(resolve(root, path), 'utf8');
const files = {
  config: read('api/config.php'),
  bootstrap: read('api/_bootstrap.php'),
  auth: read('api/auth.php'),
  oauth: read('api/oauth_lib.php'),
  app: read('src/App.tsx'),
  header: read('src/components/Header.tsx'),
  modal: read('src/components/LeadModal.tsx'),
  frontendConfig: read('src/config/registration.ts'),
};

let passed = 0;
let failed = 0;

function check(label, condition) {
  if (condition) {
    passed += 1;
    console.log(`PASS: ${label}`);
  } else {
    failed += 1;
    console.error(`FAIL: ${label}`);
  }
}

check('Frontend kayıt bayrağı kapalı', /REGISTRATION_OPEN\s*=\s*false/.test(files.frontendConfig));
check('Header ortak kayıt bayrağını kullanıyor', files.header.includes("from '../config/registration'"));
check('Kayıt sekmesi kapalıyken render edilmiyor', files.modal.includes('{REGISTRATION_OPEN && ('));
check('Doğrudan referral URL kayıt modalını açamıyor', files.app.includes('if (!REGISTRATION_OPEN || loadingSession'));
check(
  'Programatik kayıt çağrısı frontend savunmasıyla duruyor',
  files.modal.indexOf('if (!REGISTRATION_OPEN)') < files.modal.indexOf('registerWithApi(name')
);

check('Backend kayıt bayrağı varsayılan kapalı', /'registration_open'\s*=>\s*false/.test(files.config));
check('Backend kayıt kapısı merkezi yardımcıdan okunuyor', files.bootstrap.includes('function zinesh_registration_is_open'));

const emailGate = files.auth.indexOf("if ($action === 'register' && !zinesh_registration_is_open())");
const emailCreate = files.auth.indexOf('$users[] = $profile;');
check('E-posta kayıt kapısı hesap oluşturmadan önce çalışıyor', emailGate >= 0 && emailGate < emailCreate);
check('Kapalı e-posta kaydı 403 döndürüyor', files.auth.slice(emailGate, emailGate + 350).includes('], 403);'));
check('Kapalı e-posta kaydı kararlı hata kodu döndürüyor', files.auth.includes("'code' => 'registration_closed'"));

const oauthGate = files.oauth.indexOf('if ($isNew && !zinesh_registration_is_open())');
const oauthCreate = files.oauth.indexOf('$users[] = $profile;');
check('Yeni Google hesabı profil yazılmadan önce engelleniyor', oauthGate >= 0 && oauthGate < oauthCreate);
check(
  'Mevcut Google hesap yolu kayıt kapısından sonra korunuyor',
  /}\s*else\s*{\s*\$uid\s*=\s*\(string\)\$existing\['uid'\]/.test(files.oauth)
);

check('E-posta/şifre giriş endpointi korunuyor', files.auth.includes("if ($action === 'login')"));
check('Mevcut oturum yenileme endpointi korunuyor', files.auth.includes("if ($action === 'session')"));

console.log(`ÖZET: PASS=${passed} FAIL=${failed}`);
process.exitCode = failed > 0 ? 1 : 0;
