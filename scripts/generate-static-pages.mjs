/**
 * Statik SEO sayfalarını public/ altına üretir.
 * Kullanım: node scripts/generate-static-pages.mjs
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const PUBLIC = path.join(ROOT, 'public');
const SITE = 'https://www.zinesh.com';
const OG_IMAGE = `${SITE}/og-image.jpg`;

const COMMON_LINKS = [
  { href: '/nedir/', label: 'Zinesh Nedir?' },
  { href: '/blog/', label: 'Blog' },
  { href: '/sss/', label: 'SSS' },
  { href: '/', label: 'Ana site' },
];

const STYLES = `
      :root {
        color-scheme: dark;
        --bg: #030307;
        --card: #09090e;
        --border: rgba(255, 255, 255, 0.08);
        --text: #e4e4e7;
        --muted: #a1a1aa;
        --purple: #c084fc;
        --amber: #fbbf24;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
        background: var(--bg);
        color: var(--text);
        line-height: 1.75;
      }
      a { color: var(--purple); }
      .wrap { max-width: 720px; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
      .badge {
        display: inline-block;
        font-size: 0.7rem;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: var(--purple);
        border: 1px solid rgba(192, 132, 252, 0.25);
        background: rgba(192, 132, 252, 0.08);
        padding: 0.35rem 0.75rem;
        border-radius: 999px;
        margin-bottom: 1rem;
      }
      h1 {
        font-size: clamp(1.8rem, 5vw, 2.45rem);
        line-height: 1.15;
        margin: 0 0 1.25rem;
        color: #fff;
        letter-spacing: -0.02em;
      }
      h2 {
        font-size: 1.2rem;
        margin: 2.25rem 0 1rem;
        color: #fff;
      }
      h3 { font-size: 1rem; margin: 1.5rem 0 0.75rem; color: #fafafa; }
      p { color: var(--muted); margin: 0 0 1rem; font-size: 0.98rem; }
      ul, ol { color: var(--muted); margin: 0 0 1rem 1.25rem; padding: 0; }
      li { margin-bottom: 0.5rem; }
      .highlight {
        margin: 1.5rem 0;
        padding: 1.15rem 1.25rem;
        border-left: 3px solid var(--purple);
        background: rgba(192, 132, 252, 0.06);
        border-radius: 0 0.75rem 0.75rem 0;
      }
      .highlight p { color: #e4e4e7; margin-bottom: 0.65rem; }
      .card-list { display: grid; gap: 0.75rem; margin: 1.5rem 0; }
      .card-list a {
        display: block;
        padding: 1rem 1.15rem;
        border: 1px solid var(--border);
        border-radius: 0.85rem;
        background: var(--card);
        text-decoration: none;
        color: var(--text);
      }
      .card-list a:hover { border-color: rgba(192, 132, 252, 0.35); }
      .card-list strong { display: block; color: #fff; margin-bottom: 0.25rem; }
      .card-list span { font-size: 0.88rem; color: var(--muted); }
      .summary {
        margin-top: 2rem;
        padding: 1.35rem 1.5rem;
        border: 1px solid rgba(192, 132, 252, 0.2);
        background: rgba(192, 132, 252, 0.06);
        border-radius: 1rem;
      }
      .summary p { color: #e9d5ff; margin-bottom: 0.5rem; }
      .cta {
        display: inline-flex;
        margin-top: 2rem;
        padding: 0.9rem 1.4rem;
        border-radius: 999px;
        background: #fff;
        color: #000;
        font-weight: 700;
        text-decoration: none;
      }
      .cta:hover { background: #e4e4e7; }
      .links { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 2rem; }
      .links a {
        text-decoration: none;
        font-size: 0.85rem;
        padding: 0.5rem 0.9rem;
        border-radius: 999px;
        border: 1px solid var(--border);
        color: var(--text);
      }
      footer {
        margin-top: 3rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--border);
        font-size: 0.8rem;
        color: #71717a;
      }
`;

function esc(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function renderBody(page) {
  const parts = [];
  parts.push(`<span class="badge">${esc(page.badge)}</span>`);
  parts.push(`<h1>${esc(page.h1)}</h1>`);

  for (const block of page.blocks) {
    if (block.h2) parts.push(`<h2>${esc(block.h2)}</h2>`);
    if (block.h3) parts.push(`<h3>${esc(block.h3)}</h3>`);
    if (block.p) for (const para of block.p) parts.push(`<p>${para}</p>`);
    if (block.ul) {
      parts.push('<ul>');
      for (const item of block.ul) parts.push(`<li>${item}</li>`);
      parts.push('</ul>');
    }
    if (block.ol) {
      parts.push('<ol>');
      for (const item of block.ol) parts.push(`<li>${item}</li>`);
      parts.push('</ol>');
    }
    if (block.highlight) {
      parts.push('<div class="highlight">');
      for (const para of block.highlight) parts.push(`<p>${para}</p>`);
      parts.push('</div>');
    }
    if (block.cards) {
      parts.push('<div class="card-list">');
      for (const card of block.cards) {
        parts.push(
          `<a href="${esc(card.href)}"><strong>${esc(card.title)}</strong><span>${esc(card.desc)}</span></a>`,
        );
      }
      parts.push('</div>');
    }
  }

  if (page.summary) {
    parts.push('<div class="summary">');
    for (const para of page.summary) parts.push(`<p>${para}</p>`);
    parts.push('</div>');
  }

  const cta = page.cta ?? { href: '/', text: "Zinesh'e Katıl →" };
  parts.push(`<a class="cta" href="${esc(cta.href)}">${esc(cta.text)}</a>`);

  const links = page.links ?? COMMON_LINKS;
  parts.push('<div class="links">');
  for (const link of links) {
    const href = link.href.startsWith('http') ? link.href : `${SITE}${link.href}`;
    parts.push(`<a href="${esc(href)}">${esc(link.label)}</a>`);
  }
  parts.push('</div>');

  parts.push(`<footer>© Zinesh Protocol — Hizmet işlerinde güven protokolü.<br><a href="${SITE}/">www.zinesh.com</a> · <a href="${SITE}/gizlilik/">Gizlilik</a> · <a href="${SITE}/kullanim-kosullari/">Kullanım Koşulları</a></footer>`);

  return parts.join('\n      ');
}

function renderPage(page) {
  const url = `${SITE}${page.path}`;
  const jsonLd = page.jsonLd
    ? `\n    <script type="application/ld+json">\n${JSON.stringify(page.jsonLd, null, 6)}\n    </script>`
    : '';

  return `<!doctype html>
<html lang="tr">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="/favicon-48x48.png" sizes="48x48">
    <link rel="icon" type="image/png" href="/favicon-192x192.png" sizes="192x192">
    <link rel="apple-touch-icon" href="/favicon-192x192.png" sizes="192x192">
    <title>${esc(page.title)}</title>
    <meta name="description" content="${esc(page.description)}">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="${url}">
    <meta property="og:type" content="${page.ogType ?? 'article'}">
    <meta property="og:locale" content="tr_TR">
    <meta property="og:url" content="${url}">
    <meta property="og:title" content="${esc(page.ogTitle ?? page.h1)}">
    <meta property="og:description" content="${esc(page.description)}">
    <meta property="og:image" content="${OG_IMAGE}">
    <style>${STYLES}
    </style>${jsonLd}
  </head>
  <body>
    <main class="wrap">
      ${renderBody(page)}
    </main>
  </body>
</html>
`;
}

function renderRedirect(fromPath, toPath, title) {
  const to = `${SITE}${toPath}`;
  return `<!DOCTYPE html>
<html lang="tr">
  <head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="0; url=${to}">
    <link rel="canonical" href="${to}">
    <title>${esc(title)}</title>
  </head>
  <body>
    <p>Bu sayfa taşındı. <a href="${to}">${esc(title)} →</a></p>
  </body>
</html>
`;
}

const PAGES = [
  {
    slug: 'sss',
    path: '/sss/',
    badge: 'SSS',
    title: 'Sık Sorulan Sorular — Zinesh',
    description:
      'Zinesh TL emanet, escrow, komisyon ve hesap işlemleri hakkında sık sorulan sorular.',
    h1: 'Sık Sorulan Sorular',
    blocks: [
      {
        p: [
          'Zinesh hakkında en çok sorulan konuları aşağıda topladık. Daha fazla bilgi için <a href="/guven-protokolu/">Güven Protokolü</a> ve <a href="/nedir/">Zinesh Nedir</a> sayfalarına bakabilirsiniz.',
        ],
      },
      {
        h2: 'Zinesh nedir?',
        p: [
          'Zinesh bir iş ilanı sitesi değildir. Tanımadığınız biriyle anlaştığınız işin ödemesini <strong style="color:#fff">emanet kasasında</strong> tutan bir güven katmanıdır. Para doğrudan karşı tarafa gitmez; iş tamamlanınca siz onaylarsınız.',
        ],
        highlight: [
          'Amaç insanları “dürüst olmaya” zorlamak değil; kötü niyetin maliyetli olduğu öngörülebilir bir ortam kurmaktır.',
        ],
      },
      {
        h2: 'Ödemeler hangi para birimiyle yapılır?',
        p: [
          'Tüm emanetler <strong style="color:#fff">Türk Lirası (₺)</strong> ile tutulur. Site bakiyenize kart veya havale ile para yükleyebilir, emanet oluştururken tutarı kilitleyebilirsiniz.',
        ],
        ul: [
          'Para birimi: <strong>₺ (TL)</strong>',
          'Emanet tutarı iş bitene kadar kilitli kalır',
          'Onay sonrası karşı tarafa aktarılır',
        ],
      },
      {
        h2: 'Emanet nasıl oluşturulur?',
        ol: [
          'Konsolda <strong>Yeni Emanet</strong> formunu doldurun (başlık, tutar, karşı taraf e-postası)',
          'Site bakiyenizden tutarı kilitleyin',
          'İş tamamlanınca <strong>Ödemeyi serbest bırak</strong> ile onaylayın',
        ],
      },
      {
        h2: 'Komisyon oranı nedir?',
        p: [
          'Tamamlanan her emanet işleminden <strong style="color:#fff">%5</strong> protokol komisyonu alınır. Bu oran hizmetin sürdürülebilirliği içindir. Detaylar: <a href="/komisyonlar/">Komisyonlar</a>.',
        ],
      },
      {
        h2: 'Anlaşmazlık çıkarsa ne olur?',
        p: [
          'Taraflar önce kendi aralarında çözmeye çalışabilir. Çözülmezse hakemlik süreci devreye girer (yakında). Karar verildikten sonra sistem bunu uygular — örneğin tam ödeme, kısmi iade veya iptal.',
        ],
      },
      {
        h2: 'Zinesh bir banka veya yatırım platformu mu?',
        p: [
          'Hayır. Zinesh bankacılık, menkul kıymet veya yatırım danışmanlığı sunmaz. Amacı tanımadığınız taraflarla çalışırken ödemeyi güvenli kasada tutmaktır.',
        ],
      },
      {
        highlight: [
          'Hâlâ sorunuz mu var? <a href="mailto:zinesh.protocol@gmail.com">zinesh.protocol@gmail.com</a> adresine yazabilir veya <a href="https://t.me/zinesh1" rel="noopener noreferrer">Telegram</a> topluluğuna katılabilirsiniz.',
        ],
      },
    ],
    links: [
      { href: '/nedir/', label: 'Zinesh Nedir?' },
      { href: '/guven-protokolu/', label: 'Güven Protokolü' },
      { href: '/komisyonlar/', label: 'Komisyonlar' },
      { href: '/blog/', label: 'Blog' },
    ],
    jsonLd: {
      '@context': 'https://schema.org',
      '@type': 'FAQPage',
      mainEntity: [
        {
          '@type': 'Question',
          name: 'Zinesh nedir?',
          acceptedAnswer: {
            '@type': 'Answer',
            text: 'Zinesh, tanımadığınız biriyle anlaştığınız işin ödemesini emanet kasasında tutan bir güven katmanıdır.',
          },
        },
        {
          '@type': 'Question',
          name: 'Ödemeler hangi para birimiyle yapılır?',
          acceptedAnswer: {
            '@type': 'Answer',
            text: 'Tüm emanetler Türk Lirası (₺) ile tutulur. Site bakiyenize para yükleyip emanet oluşturabilirsiniz.',
          },
        },
        {
          '@type': 'Question',
          name: 'Komisyon oranı nedir?',
          acceptedAnswer: {
            '@type': 'Answer',
            text: 'Tamamlanan her emanet işleminden %5 protokol komisyonu alınır.',
          },
        },
        {
          '@type': 'Question',
          name: 'Anlaşmazlık çıkarsa ne olur?',
          acceptedAnswer: {
            '@type': 'Answer',
            text: 'Taraflar önce kendi aralarında çözmeye çalışır; çözülmezse hakemlik süreci devreye girer ve karar uygulanır.',
          },
        },
        {
          '@type': 'Question',
          name: 'Zinesh bir banka mı?',
          acceptedAnswer: {
            '@type': 'Answer',
            text: 'Hayır. Zinesh emanet ve güven koordinasyonu sunar; bankacılık veya yatırım hizmeti değildir.',
          },
        },
      ],
    },
  },
  {
    slug: 'guven-protokolu',
    path: '/guven-protokolu/',
    badge: 'Protokol',
    title: 'Güven Protokolü — Zinesh Nasıl Güvence Sağlar?',
    description: 'Zinesh güven protokolü: TL emanet kasası ile tanımadığınız taraflarla güvenle iş yapın.',
    h1: 'Güven Protokolü',
    blocks: [
      {
        p: [
          'Zinesh, güveni insanların vicdanına değil <strong style="color:#fff">önceden belirlenmiş kurallara</strong> emanet eder. Taraflar anlaşır, ödeme ₺ emanet kasasında bekler, iş tamamlanınca onaylanır.',
        ],
      },
      {
        h2: 'Üç adımda emanet',
        ol: [
          '<strong>Emanet oluştur</strong> — Tutarı site bakiyenden kilitle',
          '<strong>İşi tamamla</strong> — Karşı taraf teslim eder',
          '<strong>Onayla</strong> — Ödemeyi serbest bırak',
        ],
      },
      {
        h2: 'Kimler için?',
        p: [
          'Freelance yazılımcılar, tasarımcılar, danışmanlar, çevirmenler ve hizmet sektöründeki herkes — internette tanımadığı biriyle çalışırken.',
        ],
      },
      {
        highlight: [
          'Amaç insanları kontrol etmek değil; birbirine güvenmek zorunda kalmadan birlikte çalışabilmelerini sağlamaktır.',
        ],
      },
    ],
    cta: { href: '/', text: 'Hesap Oluştur →' },
  },
  {
    slug: 'komisyonlar',
    path: '/komisyonlar/',
    badge: 'Ekonomi',
    title: 'Komisyonlar — %5 Protokol Ücreti | Zinesh',
    description: 'Zinesh emanet komisyonu: tamamlanan işlerde %5 protokol ücreti; tamamı sistem kasasına kalır.',
    h1: 'Komisyonlar',
    blocks: [
      {
        p: ['Her tamamlanan emanet işleminden <strong style="color:#fff">%5</strong> protokol komisyonu alınır. Token / Web3 dağılımı yoktur — komisyonun tamamı <strong style="color:#fff">sistem kasasına</strong> kalır.'],
      },
      {
        h2: 'Ne zaman kesilir?',
        ul: [
          'Komisyon yalnızca <strong>başarıyla tamamlanan</strong> emanetlerde uygulanır',
          'İş alan %95 alır; %5 sistemde kalır',
          'İptal edilen veya iade edilen işlerde farklı kurallar geçerli olabilir',
          'Tutarlar Türk Lirası (₺) cinsindendir',
        ],
      },
      {
        h2: 'Kart yatırma',
        p: ['Kart ile bakiye yüklemede ödeme sağlayıcısı (iyzico) komisyonu ayrıca uygulanabilir. Bu, protokol komisyonundan bağımsızdır.'],
      },
    ],
    links: [
      { href: '/guven-protokolu/', label: 'Güven Protokolü' },
      { href: '/sss/', label: 'SSS' },
    ],
  },
  {
    slug: 'hakemlik',
    path: '/hakemlik/',
    badge: 'Tahkim',
    title: 'Hakemlik Sistemi — Zinesh Anlaşmazlık Çözümü',
    description: 'Zinesh hakemlik: anlaşmazlıklarda tarafsız tahkim süreci (yakında).',
    h1: 'Hakemlik Sistemi',
    blocks: [
      {
        p: [
          'İki taraf anlaşamazsa <strong style="color:#fff">bağımsız hakemler</strong> devreye girer. Her işin bağlamı farklı olduğu için insan değerlendirmesi gerekir.',
        ],
      },
      {
        h2: 'Kademeli tahkim',
        ol: [
          'Önce <strong>5 hakem</strong> inceler',
          'Yetmezse <strong>11 hakeme</strong> çıkarılır',
          'Hâlâ net değilse <strong>21 hakem</strong> devreye girer',
        ],
      },
      {
        h2: 'Durum',
        p: [
          'TL emanet modunda hakemlik altyapısı hazırlanıyor. Şimdilik taraflar anlaşmazlıkları doğrudan çözebilir veya destek ekibiyle iletişime geçebilir.',
        ],
      },
      {
        highlight: ['Hakemlik, yatırım getirisi değil; adil kararın ve emeğin karşılığıdır.'],
      },
    ],
    links: [
      { href: '/guven-protokolu/', label: 'Güven Protokolü' },
      { href: '/sss/', label: 'SSS' },
    ],
  },
  {
    slug: 'gizlilik',
    path: '/gizlilik/',
    badge: 'Yasal',
    title: 'Gizlilik Politikası — Zinesh',
    description: 'Zinesh gizlilik politikası: hangi verileri topluyoruz, nasıl saklıyoruz ve haklarınız nelerdir.',
    h1: 'Gizlilik Politikası',
    ogType: 'website',
    blocks: [
      { p: ['<strong style="color:#fff">Son güncelleme:</strong> Haziran 2026'] },
      {
        h2: 'Toplanan veriler',
        ul: [
          'Hesap bilgileri (e-posta, profil)',
          'KYC doğrulama belgeleri (yalnızca doğrulama amacıyla)',
          'İşlem ve escrow kayıtları',
          'Teknik loglar (IP, tarayıcı, oturum güvenliği)',
        ],
      },
      {
        h2: 'Verilerin kullanımı',
        p: ['Veriler yalnızca protokol operasyonları, güvenlik, hakemlik süreçleri ve yasal yükümlülükler için kullanılır. Üçüncü taraflara satılmaz.'],
      },
      {
        h2: 'Haklarınız',
        p: ['KVKK kapsamında verilerinize erişim, düzeltme ve silme talebinde bulunabilirsiniz: <a href="mailto:zinesh.protocol@gmail.com">zinesh.protocol@gmail.com</a>'],
      },
      {
        h2: 'Çerezler',
        p: ['Oturum yönetimi ve güvenlik için gerekli çerezler kullanılır. Reklam amaçlı üçüncü taraf çerezleri kullanılmaz.'],
      },
    ],
    cta: { href: '/kullanim-kosullari/', text: 'Kullanım Koşulları →' },
  },
  {
    slug: 'kullanim-kosullari',
    path: '/kullanim-kosullari/',
    badge: 'Yasal',
    title: 'Kullanım Koşulları — Zinesh',
    description: 'Zinesh platformu kullanım koşulları, sorumluluk sınırları ve protokol kuralları.',
    h1: 'Kullanım Koşulları',
    ogType: 'website',
    blocks: [
      { p: ['<strong style="color:#fff">Son güncelleme:</strong> Haziran 2026'] },
      {
        h2: 'Hizmetin kapsamı',
        p: ['Zinesh bir güven protokolü ve escrow koordinasyon katmanıdır. Yatırım danışmanlığı, bankacılık veya menkul kıymet hizmeti sunmaz.'],
      },
      {
        h2: 'Ödemeler',
        ul: [
          'Tüm emanetler Türk Lirası (₺) ile tutulur',
          'Zinesh yatırım veya kâr vaadi sunmaz',
          'Protokol yalnızca emanet koordinasyonu sağlar',
        ],
      },
      {
        h2: 'Kullanıcı sorumlulukları',
        ul: [
          'Doğru ve güncel bilgi vermek',
          'Hakemlik süreçlerine saygı göstermek',
          'Dolandırıcılık ve kötü niyetli davranışlardan kaçınmak',
        ],
      },
      {
        h2: 'Sorumluluk sınırı',
        p: ['Zinesh, taraflar arasındaki sözleşme içeriğinden sorumlu değildir. Protokol kuralları ve hakem kararları çerçevesinde koordinasyon sağlar.'],
      },
    ],
    cta: { href: '/gizlilik/', text: 'Gizlilik Politikası →' },
  },
  {
    slug: 'blog',
    path: '/blog/',
    badge: 'Blog',
    title: 'Zinesh Blog — Güven, Escrow ve Freelance',
    description: 'Zinesh blog: yazılı sözleşme, güvenli emanet ve freelance dolandırıcılıktan korunma üzerine yazılar.',
    h1: 'Zinesh Blog',
    ogType: 'website',
    blocks: [
      {
        p: ['Önce sözleşme sonra kasa — güvenli emanet ve freelance ekonomisi hakkında yazılar.'],
      },
      {
        cards: [
          { href: '/blog/zinesh-nedir/', title: 'Zinesh Nedir?', desc: 'Emanet kasasının temelleri ve neden önemli olduğu.' },
          { href: '/blog/usdt-ile-guvenli-is/', title: 'Güvenli Ödeme ile İş', desc: 'Yazılı sözleşme ve emanet kasası ile tanımadığınız biriyle nasıl çalışırsınız?' },
          { href: '/blog/freelance-dolandiricilik/', title: 'Freelance Dolandırıcılık', desc: 'Tanımadığınız biriyle çalışırken riskler ve çözümler.' },
          { href: '/blog/web3-ve-gercek-ekonomi/', title: 'Güven ve Gerçek İş', desc: 'Spekülasyon değil, gerçek iş ve yazılı anlaşma.' },
        ],
      },
    ],
  },
  {
    slug: 'blog/zinesh-nedir',
    path: '/blog/zinesh-nedir/',
    badge: 'Blog',
    title: 'Zinesh Nedir? — Blog | Zinesh',
    description: 'Zinesh güven protokolü nedir, nasıl çalışır ve freelance ekonomisinde neden fark yaratır?',
    h1: 'Zinesh Nedir?',
    blocks: [
      { p: ['İnternet insanları birbirine yaklaştırdı ama güven sorununu çözmedi. Zinesh, tanımadığınız biriyle iş yaparken paranızı ve emeğinizi koruyan bir protokoldür.'] },
      { h2: 'Nasıl çalışır?', p: ['Ödeme doğrudan karşı tarafa gitmez — önce ₺ emanet kasasında bekler. İş tamamlanınca onaylarsınız. Sorun olursa hakemlik devreye girer.'] },
      { p: ['Detaylı anlatım için <a href="/nedir/">Zinesh Nedir sayfasına</a> bakın.'] },
    ],
    jsonLd: {
      '@context': 'https://schema.org',
      '@type': 'BlogPosting',
      headline: 'Zinesh Nedir?',
      url: `${SITE}/blog/zinesh-nedir/`,
      publisher: { '@type': 'Organization', name: 'Zinesh', url: SITE },
      inLanguage: 'tr-TR',
    },
  },
  {
    slug: 'blog/usdt-ile-guvenli-is',
    path: '/blog/usdt-ile-guvenli-is/',
    badge: 'Blog',
    title: 'Güvenli Ödeme ile İş Yapmak — Zinesh Blog',
    description: 'Tanımadığınız biriyle iş yaparken emanet kasası nasıl güvence sağlar?',
    h1: 'Güvenli Ödeme ile İş Yapmak',
    blocks: [
      { p: ['İnternette iş yaparken en büyük risk, parayı gönderdikten sonra karşı tarafın ortadan kaybolmasıdır. Emanet (escrow) bu riski ortadan kaldırır.'] },
      { h2: 'Emanet çözümü', p: ['Zinesh\'te ödeme ₺ emanet kasasında kilitlenir. Siz onaylayana veya hakem kararı kesinleşene kadar serbest bırakılmaz.'] },
      { h2: 'Nasıl başlarsınız?', p: ['Site bakiyenize TL yükleyin, <strong>Yeni Emanet</strong> formunu doldurun ve karşı tarafın e-postasını girin.'] },
      { highlight: ['Zinesh bir iş ilanı sitesi değil — yalnızca anlaştığınız işin ödemesini güvence altına alır.'] },
    ],
    jsonLd: {
      '@context': 'https://schema.org',
      '@type': 'BlogPosting',
      headline: 'Güvenli Ödeme ile İş Yapmak',
      url: `${SITE}/blog/usdt-ile-guvenli-is/`,
      publisher: { '@type': 'Organization', name: 'Zinesh', url: SITE },
      inLanguage: 'tr-TR',
    },
  },
  {
    slug: 'blog/freelance-dolandiricilik',
    path: '/blog/freelance-dolandiricilik/',
    badge: 'Blog',
    title: 'Freelance Dolandırıcılık ve Korunma — Zinesh Blog',
    description: 'Freelance dolandırıcılık türleri, yaygın tuzaklar ve Zinesh escrow ile korunma yolları.',
    h1: 'Freelance Dolandırıcılık ve Korunma',
    blocks: [
      { p: ['Freelance ekonomisi büyüdükçe dolandırıcılık vakaları da arttı. "Önce işi yap, sonra öderim" veya "Parayı gönder, hemen başlarım" cümleleri her iki tarafta da risk taşır.'] },
      { h2: 'Yaygın senaryolar', ul: ['Ödeme almadan teslimat', 'Ödeme sonrası kaybolma', 'Kısmi iş ve anlaşmazlık', 'Sahte referans ve kimlik'] },
      { h2: 'Nasıl korunursunuz?', ol: ['Ödemeyi emanet kasasına koyun', 'Karşı tarafın e-postasını doğru girin', 'İş bitmeden onay vermeyin', 'Anlaşmazlıkta destek ekibiyle iletişime geçin'] },
    ],
    jsonLd: {
      '@context': 'https://schema.org',
      '@type': 'BlogPosting',
      headline: 'Freelance Dolandırıcılık ve Korunma',
      url: `${SITE}/blog/freelance-dolandiricilik/`,
      publisher: { '@type': 'Organization', name: 'Zinesh', url: SITE },
      inLanguage: 'tr-TR',
    },
  },
  {
    slug: 'blog/web3-ve-gercek-ekonomi',
    path: '/blog/web3-ve-gercek-ekonomi/',
    badge: 'Blog',
    title: 'Güven ve Gerçek İş — Zinesh Blog',
    description: 'Spekülasyon değil gerçek iş yapan protokoller için ne anlama gelir? Zinesh perspektifi.',
    h1: 'Güven ve Gerçek İş',
    blocks: [
      { p: ['Birçok dijital proje spekülasyon etrafında döner. Zinesh farklı bir soruya cevap verir: <strong style="color:#fff">İnsanlar tanımadıkları biriyle nasıl güvenle çalışır?</strong>'] },
      { h2: 'Gerçek iş ne demek?', p: ['Gerçek iş hacmi, emanet, teslimat ve adil sonuç — bunlar spekülatif fiyat hareketinden bağımsız değer yaratır.'] },
      { h2: 'Zinesh yaklaşımı', ul: ['TL emanet kasası', '%5 şeffaf komisyon', 'Basit üç adımlı akış', 'İş ilanı değil, güven katmanı'] },
      { p: ['Detaylar için <a href="/guven-protokolu/">Güven Protokolü</a> ve <a href="/sss/">SSS</a> sayfalarına bakın.'] },
    ],
    jsonLd: {
      '@context': 'https://schema.org',
      '@type': 'BlogPosting',
      headline: 'Güven ve Gerçek İş',
      url: `${SITE}/blog/web3-ve-gercek-ekonomi/`,
      publisher: { '@type': 'Organization', name: 'Zinesh', url: SITE },
      inLanguage: 'tr-TR',
    },
  },
];

// whitepaper → teknik doküman yönlendirmesi + kısa landing
const WHITEPAPER_LANDING = {
  slug: 'whitepaper',
  path: '/whitepaper/',
  badge: 'Dokümantasyon',
  title: 'Zinesh Teknik Doküman',
  description: 'Zinesh teknik dokümantasyon. TL emanet, yazılı sözleşme ve güven mekanizmaları.',
  h1: 'Teknik doküman',
  blocks: [
    {
      p: [
        'Zinesh teknik dokümantasyonu emanet kurallarını, yazılı sözleşme akışını ve operasyon adımlarını anlatır.',
      ],
    },
    {
      cards: [
        { href: '/teknik-dokuman/', title: 'Teknik Doküman v1.0', desc: 'Emanet kuralları ve işleyiş.' },
        { href: '/komisyonlar/', title: 'Komisyonlar', desc: '%5 emanet ücreti.' },
        { href: '/sss/', title: 'SSS', desc: 'Sık sorulan sorular.' },
      ],
    },
  ],
  cta: { href: '/teknik-dokuman/', text: 'Teknik Dokümana Git →' },
};

PAGES.splice(4, 0, WHITEPAPER_LANDING);

function writePage(page) {
  const dir = path.join(PUBLIC, page.slug);
  fs.mkdirSync(dir, { recursive: true });
  const file = path.join(dir, 'index.html');
  fs.writeFileSync(file, renderPage(page), 'utf8');
  console.log('WROTE', page.path);
}

for (const page of PAGES) {
  writePage(page);
}

// Eski sayfalar → yönlendirme
const LEGACY_REDIRECTS = [
  { slug: 'fizi', from: '/fizi/', to: '/guven-protokolu/', title: 'Güven Protokolü' },
  { slug: 'erken-erisim', from: '/erken-erisim/', to: '/', title: 'Zinesh' },
  { slug: 'economic-history', from: '/economic-history/', to: '/guven-protokolu/', title: 'Güven Protokolü' },
  { slug: 'baraj-sistemi', from: '/baraj-sistemi/', to: '/komisyonlar/', title: 'Komisyonlar' },
];

for (const r of LEGACY_REDIRECTS) {
  const dir = path.join(PUBLIC, r.slug);
  fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(path.join(dir, 'index.html'), renderRedirect(r.from, r.to, r.title), 'utf8');
  console.log(`WROTE ${r.from} redirect → ${r.to}`);
}

// Sitemap güncelle
const EXISTING_SITEMAP_URLS = [
  { loc: '/', priority: '1.0', changefreq: 'weekly' },
  { loc: '/nedir/', priority: '0.9', changefreq: 'monthly' },
  { loc: '/kurucu/', priority: '0.8', changefreq: 'monthly' },
  { loc: '/tanitim/', priority: '0.7', changefreq: 'monthly' },
  { loc: '/paylas/', priority: '0.7', changefreq: 'monthly' },
  { loc: '/teknik-dokuman/', priority: '0.85', changefreq: 'monthly' },
  { loc: '/dipnot/', priority: '0.3', changefreq: 'yearly' },
];

const NEW_URLS = PAGES.map((p) => ({
  loc: p.path,
  priority: p.slug.startsWith('blog/') ? '0.65' : p.slug === 'blog' ? '0.75' : ['gizlilik', 'kullanim-kosullari'].includes(p.slug) ? '0.4' : '0.8',
  changefreq: p.slug.startsWith('blog') ? 'monthly' : 'monthly',
}));

const allUrls = [...EXISTING_SITEMAP_URLS, ...NEW_URLS];
const sitemap = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${allUrls
  .map(
    (u) => `  <url>
    <loc>${SITE}${u.loc}</loc>
    <changefreq>${u.changefreq}</changefreq>
    <priority>${u.priority}</priority>
  </url>`,
  )
  .join('\n')}
</urlset>
`;

fs.writeFileSync(path.join(PUBLIC, 'sitemap.xml'), sitemap, 'utf8');
console.log('UPDATED sitemap.xml (' + allUrls.length + ' URLs)');
