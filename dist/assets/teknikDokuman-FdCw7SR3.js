import{j as e,r as x,c as j}from"./vendor-react-Bb048-hH.js";import{i as N,j as T}from"./vendor-lucide-CcTOozSS.js";/* empty css              */const y=`# Zinesh Teknik Dokümanı\r
\r
**White Paper · Sürüm 1.1**  \r
**Temmuz 2026 · TL emanet üretim referansı**\r
\r
---\r
\r
> Tanımadığın biriyle iş yapmak, internetin en eski ve en pahalı sorunlarından biridir. Zinesh bu sorunu spekülasyonla değil; **kurallar, emanet kasası ve kanıt** ile çözmeyi hedefler.\r
\r
---\r
\r
## 1. Önsöz\r
\r
İnternet bilgiyi taşıdı. Ödeme ağları parayı taşıdı. Ama **güveni** taşımadı.\r
\r
Freelance işler, uzaktan hizmetler ve küçük ticaret hâlâ aynı riskle yüz yüze: *“Önce ben ödersem kaybederim; önce o yaparsa o kaybeder.”* Platformlar bu boşluğu aracılık komisyonu ve opak kurallarla doldurdu. Zinesh farklı bir yere bakar: güvenin bir marka vaadi değil, **doğrulanabilir bir protokol davranışı** olması gerektiğine inanır.\r
\r
Bu belge üç katmanı bir arada sunar:\r
\r
- **Felsefe** — protokolün değişmez niyeti  \r
- **v1.1 gerçeği** — bugün üretimde çalışan TL emanet sistemi  \r
- **Hedef tasarım** — henüz tamamlanmamış, yol haritasındaki mekanizmalar  \r
\r
Okuyucu, hangi cümlenin hangi katmana ait olduğunu metin içinde açıkça görecektir. Yatırım vaadi yoktur; abartı yoktur.\r
\r
---\r
\r
## 2. Problem: Güvenin Eksik Protokolü\r
\r
### 2.1 İkili güvensizlik\r
\r
Hizmet ekonomisinde iki taraf da haklı olarak şüphe duyar. Alıcı ödemeyi önceden yaparsa teslimat riski taşır; satıcı önce çalışırsa tahsilat riski taşır. Klasik çözümler — referans, platform itibarı, mahkeme — yavaş, pahalı veya sınır ötesi işlerde erişilemez kalır.\r
\r
### 2.2 Zinesh’in cevabı\r
\r
Zinesh şunu önerir:\r
\r
1. **İş bedeli TL olarak emanet kasasında kilitlenir** — para ne alıcıda ne satıcıda serbest dolaşır; anlaşmaya bağlıdır.  \r
2. **Uyuşmazlıkta bağımsız hakemler devreye girer** (hedef tasarım) — karar kanıtlara dayanır.  \r
3. **Güven puanı (TrustScore) zaman içinde kazanılır** — parayla satın alınamaz.\r
\r
Bu üçlü, bir “kripto projesi” anlatısından önce **ticaret güvenliği** anlatısıdır.\r
\r
---\r
\r
## 3. Protokol İlkeleri\r
\r
Aşağıdaki ilkeler Zinesh’in anayasasıdır. Teknik kararlar bunlara aykırı olamaz.\r
\r
### 3.1 Güven satın alınamaz\r
\r
Güven puanı ve hakemlik geçmişi **zaman ve davranışla** kazanılır. Sahte hesaplar, yapay iş döngüleri ve yalnızca bekleme ile simüle edilen geçmiş anlamlı katkı sayılmaz.\r
\r
### 3.2 TL iş parası\r
\r
İş ödemeleri ve teminatlar **Türk Lirası (₺)** cinsindendir. Site cüzdanında TL bakiyesi tutulur; emanet oluşturulduğunda tutar kasada kilitlenir. Zinesh bir yatırım ürünü veya kripto borsası değildir.\r
\r
### 3.3 Şeffaflık ve dürüst ticaret\r
\r
Protokol, gizli ceza tuzağı veya getiri vaadi üzerine kurulmamıştır. Kullanıcılar iş kurallarını ve komisyonları önceden bilir.\r
\r
### 3.4 İnsan hakemleri, makine değil\r
\r
Uyuşmazlık kararları hedef tasarımda **insan hakemleri** tarafından verilir. Yapay zeka modelleri nihai karar veremez.\r
\r
---\r
\r
## 4. Sistem Mimarisi (v1.1)\r
\r
v1.1 üretim ortamı merkezi sunucu mimarisidir. Bu bir zayıflık gizlemez; aşamalı olgunlaşma stratejisidir.\r
\r
### 4.1 Bileşenler\r
\r
\`\`\`\r
[Kullanıcı] ──HTTPS──► Nginx ──► PHP-FPM (api/*.php)\r
                              │\r
                              ▼\r
                    /www/server/zinesh-data/*.json\r
                              │\r
                              ▼\r
                    Yedekleme (rclone → Google Drive)\r
\`\`\`\r
\r
| Katman | Görev |\r
|--------|-------|\r
| SPA (React) | Kullanıcı arayüzü, emanet akışları, cüzdan |\r
| PHP API | Kimlik, cüzdan, kampanya, emanet mutasyonları |\r
| JSON veri | \`users.json\`, \`sessions.json\`, emanet kayıtları |\r
| Nginx | TLS, güvenlik başlıkları, hassas dosya engeli |\r
\r
### 4.2 Oturum ve kimlik\r
\r
Oturum token’ları sunucuda \`sessions.json\` içinde tutulur. Giriş: e-posta + şifre veya Google OAuth (GIS). Kurucu hesaplarında isteğe bağlı TOTP (Google Authenticator) devrededir. Başarısız giriş denemelerinde e-posta doğrulama, paralel giriş kilidi ve spam koruması uygulanır.\r
\r
### 4.3 Atomik yazım\r
\r
Kritik bakiye değişiklikleri dosya kilidi altında yapılır. Paralel isteklerde çift harcama ve tutarsız bakiye riski azaltılır.\r
\r
---\r
\r
## 5. Emanet ve İş Akışı\r
\r
### 5.1 Emanet modeli (v1.1)\r
\r
- Alıcı veya işveren iş bedelini **TL** olarak site cüzdanına yatırır.  \r
- Emanet oluşturulduğunda tutar **kilitlenir** (\`escrowBalance\`).  \r
- Çekilebilir bakiye: \`tlBalance − escrowBalance\`.  \r
- İş tamamlandığında komisyon düşülür; kalan tutar karşı tarafa aktarılır.\r
\r
### 5.2 Komisyon yapısı\r
\r
Tamamlanan emanetlerde **%5** protokol komisyonu uygulanır. Bu pay operasyon ve platform sürdürülebilirliği için ayrılır.\r
\r
| Pay | Açıklama |\r
|-----|----------|\r
| Protokol komisyonu | %5 — emanet serbest bırakıldığında |\r
\r
Detaylı dağılım: [Komisyonlar](/komisyonlar/).\r
\r
### 5.3 Ödeme yöntemleri (v1.1)\r
\r
| Yöntem | Durum |\r
|--------|-------|\r
| Havale / EFT bildirimi | Yapılandırıldığında aktif |\r
| Kart (iyzico) | Yapılandırıldığında aktif |\r
| IBAN çekim | Faz 2 — planlanıyor |\r
\r
### 5.4 Hedef tahkim (henüz tam üretimde değil)\r
\r
Hedef tasarımda uyuşmazlık **bağımsız hakemler** tarafından, kanıt dosyalarıyla çözülür. v1.1’de emanet oluşturma, kilitleme ve serbest bırakma aktiftir; tam otomatik hakemlik paneli yol haritasındadır.\r
\r
---\r
\r
## 6. Kurucu Üye Programı (Erken Erişim)\r
\r
| Parametre | Değer |\r
|-----------|-------|\r
| Kontenjan | 500 kullanıcı |\r
| Görevler | E-posta doğrulama, TL yatırma, KYC, 3 referans, ilk emanet |\r
\r
Kontenjan dolduğunda program sona erer. Amaç erken kullanıcıları toplamak ve gerçek emanet akışını test etmektir; token veya kripto ödülü yoktur.\r
\r
---\r
\r
## 7. Güven Puanı (TrustScore)\r
\r
TrustScore (0–100), protokol içi davranışın özetidir. Yeni kullanıcı **50** ile başlar. Güven puanı **parayla satın alınamaz**.\r
\r
Hedef tasarımda skor; tamamlanan emanetler, hakemlik performansı ve haksız taraf cezalarıyla güncellenir. Her emanet kaydı güven profilinde görüntülenebilir (gizlilik ayarına bağlı).\r
\r
---\r
\r
## 8. Güvenlik ve Operasyon\r
\r
### 8.1 Sunucu sertleştirme\r
\r
- Nginx ile hassas PHP/JSON dosyalarına doğrudan erişim engeli  \r
- CSP ve güvenlik başlıkları  \r
- IP tabanlı rate limit  \r
- Denetim günlüğü (\`audit_log.json\`)\r
\r
### 8.2 Yedekleme\r
\r
Günde üç kez otomatik yedek; bulut depoda saklama. Geri yükleme prosedürü sunucu yöneticisi tarafından uygulanır.\r
\r
### 8.3 v1.1 şeffaflık sınırı\r
\r
Bakiyeler ve emanet kayıtları merkezi muhasebededir. Kullanıcılar kendi işlem geçmişlerini konsoldan görür. Halka açık blokzincir doğrulaması yoktur; üretim yolu PHP + JSON’dur.\r
\r
---\r
\r
## 9. API Özeti\r
\r
| Uç | Dosya | Açıklama |\r
|----|-------|----------|\r
| Kimlik | \`auth.php\` | Kayıt, giriş, Google OAuth, oturum, şifre sıfırlama |\r
| Cüzdan | \`wallet.php\` | TL bakiye, emanet oluşturma/serbest bırakma, havale bildirimi |\r
| Ödeme | \`payment.php\` | Kart ile TL yatırma (iyzico) |\r
| Kampanya | \`campaign_public.php\` | Erken erişim durumu |\r
| Güven profili | \`trust_profile.php\` | Emanet geçmişi, gizlilik |\r
\r
Tüm yazma istekleri \`POST\` + JSON gövde ile yapılır.\r
\r
---\r
\r
## 10. Yol Haritası\r
\r
| Aşama | Odak |\r
|-------|------|\r
| v1.1 (mevcut) | TL emanet, e-posta/Google giriş, havale/kart yatırma, güven profili |\r
| v1.x | IBAN çekim, hakemlik paneli, TrustScore otomasyonu |\r
| v2+ | Gelişmiş tahkim, çok taraflı emanet şablonları |\r
\r
Yol haritası taahhüt değil; öncelik sırasıdır. Özellikler üretim kanıtı olmadan “aktif” ilan edilmez.\r
\r
---\r
\r
## 11. Yasal Bilgilendirme\r
\r
Zinesh bir yatırım ürünü, kripto borsası veya getiri vaadi sunan platform değildir. Tanımadığınız kişilerle yaptığınız anlaşmalarda paranız emanet kasasında tutulur; iş bitince siz onaylarsınız. Anlaşmazlıkta hakem süreçleri devreye girer (hedef tasarım).\r
\r
---\r
\r
## 12. Sürüm Geçmişi\r
\r
| Sürüm | Tarih | Not |\r
|-------|-------|-----|\r
| 1.0 | Haziran 2026 | İlk white paper |\r
| 1.1 | Temmuz 2026 | TL emanet odaklı revizyon; kripto/token ekonomisi kaldırıldı |\r
\r
---\r
\r
*© 2026 Zinesh Protocol — Kurucu: Yasin Karademir*\r
`;function v(i){return i.trim().toLowerCase().replace(/ı/g,"i").replace(/ğ/g,"g").replace(/ü/g,"u").replace(/ş/g,"s").replace(/ö/g,"o").replace(/ç/g,"c").replace(/[^a-z0-9]+/g,"-").replace(/^-|-$/g,"")}function _(i){const a=[];for(const n of i.split(/\r?\n/)){const r=/^(#{2,3})\s+(.+)$/.exec(n.trim());if(!r)continue;const t=r[1].length,u=r[2].replace(/\*\*/g,"").trim();a.push({id:v(u),text:u,level:t})}return a}function P(i){return i.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;")}function p(i){let a=P(i);return a=a.replace(/`([^`]+)`/g,'<code class="md-inline-code">$1</code>'),a=a.replace(/\*\*([^*]+)\*\*/g,'<strong class="text-zinc-100 font-semibold">$1</strong>'),a=a.replace(/\*([^*]+)\*/g,'<em class="text-zinc-300 not-italic">$1</em>'),a}function g(i){return i.trim().replace(/^\|/,"").replace(/\|$/,"").split("|").map(a=>a.trim())}const m={h1:"font-display text-4xl sm:text-5xl font-black text-white tracking-tight leading-[1.08] mb-3 scroll-mt-28",h2:"font-display text-2xl sm:text-[1.65rem] font-bold text-white tracking-tight mt-16 mb-5 pt-10 border-t border-white/[0.07] scroll-mt-28 first:mt-0 first:pt-0 first:border-t-0",h3:"font-sans text-lg font-semibold text-zinc-100 mt-8 mb-3 scroll-mt-28",p:"my-4 text-[0.98rem] sm:text-[1.02rem] text-zinc-400 leading-[1.85] font-sans font-medium",hr:"my-14 border-0 h-px bg-gradient-to-r from-transparent via-white/15 to-transparent",blockquote:"my-8 rounded-2xl border border-purple-500/20 bg-gradient-to-br from-purple-500/[0.08] to-transparent px-5 py-4 sm:px-6 sm:py-5 text-[1.02rem] text-zinc-200 leading-relaxed font-sans shadow-[inset_0_1px_0_rgba(255,255,255,0.04)]",ul:"my-5 space-y-2.5 pl-0 list-none",ulItem:"relative pl-5 text-zinc-400 text-[0.98rem] leading-relaxed font-sans before:absolute before:left-0 before:top-[0.55em] before:h-1.5 before:w-1.5 before:rounded-full before:bg-amber-400/80",ol:"my-5 space-y-3 pl-0 list-none counter-reset-md-ol",olItem:"relative pl-8 text-zinc-400 text-[0.98rem] leading-relaxed font-sans [counter-increment:md-ol] before:absolute before:left-0 before:top-0 before:flex before:h-6 before:w-6 before:items-center before:justify-center before:rounded-full before:bg-white/[0.06] before:font-mono before:text-[10px] before:font-bold before:text-amber-300/90 before:content-[counter(md-ol)]",pre:"my-6 overflow-x-auto rounded-2xl border border-white/[0.08] bg-[#050508] p-5 text-[0.82rem] font-mono text-zinc-300 leading-relaxed shadow-inner",tableWrap:"my-8 overflow-x-auto rounded-2xl border border-white/[0.08] bg-white/[0.02]",th:"px-4 py-3 text-left font-mono text-[10px] uppercase tracking-widest text-amber-300/90 font-bold",td:"px-4 py-3 text-sm text-zinc-400 font-sans border-t border-white/[0.05]"};function S({markdown:i}){const a=i.split(/\r?\n/),n=[];let r=0,t=0;for(;r<a.length;){const s=a[r].trim();if(s===""){r+=1;continue}if(s==="---"){n.push(e.jsx("hr",{className:m.hr},t++)),r+=1;continue}const c=/^(#{1,3})\s+(.+)$/.exec(s);if(c){const l=c[1].length,o=c[2].replace(/\*\*/g,""),d=v(o),h=p(o);l===1?n.push(e.jsx("h1",{id:d,className:m.h1,dangerouslySetInnerHTML:{__html:h}},t++)):l===2?n.push(e.jsx("h2",{id:d,className:m.h2,dangerouslySetInnerHTML:{__html:h}},t++)):n.push(e.jsx("h3",{id:d,className:m.h3,dangerouslySetInnerHTML:{__html:h}},t++)),r+=1;continue}if(s.startsWith("```")){const l=s.slice(3).trim(),o=[];for(r+=1;r<a.length&&!a[r].trim().startsWith("```");)o.push(a[r]),r+=1;r+=1,n.push(e.jsx("pre",{className:m.pre,"data-lang":l||void 0,children:e.jsx("code",{children:o.join(`
`)})},t++));continue}if(s.startsWith("|")&&r+1<a.length&&/^\|?[\s:-]+\|/.test(a[r+1])){const l=g(s);r+=2;const o=[];for(;r<a.length&&a[r].trim().startsWith("|");)o.push(g(a[r])),r+=1;n.push(e.jsx("div",{className:m.tableWrap,children:e.jsxs("table",{className:"w-full min-w-[280px] border-collapse",children:[e.jsx("thead",{className:"bg-white/[0.03]",children:e.jsx("tr",{children:l.map(d=>e.jsx("th",{className:m.th,children:d},d))})}),e.jsx("tbody",{children:o.map((d,h)=>e.jsx("tr",{className:"hover:bg-white/[0.02] transition-colors",children:d.map((z,w)=>e.jsx("td",{className:m.td,children:e.jsx("span",{dangerouslySetInnerHTML:{__html:p(z)}})},w))},h))})]})},t++));continue}if(s.startsWith("> ")){const l=[];for(;r<a.length&&a[r].trim().startsWith("> ");)l.push(a[r].trim().slice(2)),r+=1;n.push(e.jsx("blockquote",{className:m.blockquote,dangerouslySetInnerHTML:{__html:p(l.join(" "))}},t++));continue}if(/^\d+\.\s/.test(s)){const l=[];for(;r<a.length&&/^\d+\.\s/.test(a[r].trim());)l.push(a[r].trim().replace(/^\d+\.\s+/,"")),r+=1;n.push(e.jsx("ol",{className:m.ol,children:l.map(o=>e.jsx("li",{className:m.olItem,dangerouslySetInnerHTML:{__html:p(o)}},o))},t++));continue}if(s.startsWith("- ")){const l=[];for(;r<a.length&&a[r].trim().startsWith("- ");)l.push(a[r].trim().slice(2)),r+=1;n.push(e.jsx("ul",{className:m.ul,children:l.map(o=>e.jsx("li",{className:m.ulItem,dangerouslySetInnerHTML:{__html:p(o)}},o))},t++));continue}const k=[s];for(r+=1;r<a.length&&a[r].trim()!==""&&!/^(#{1,3}|```|\||>|---|- |\d+\.\s)/.test(a[r].trim());)k.push(a[r].trim()),r+=1;n.push(e.jsx("p",{className:m.p,dangerouslySetInnerHTML:{__html:p(k.join(" "))}},t++))}return e.jsx("div",{className:"markdown-doc [&_.md-inline-code]:rounded-md [&_.md-inline-code]:bg-white/[0.06] [&_.md-inline-code]:border [&_.md-inline-code]:border-white/[0.08] [&_.md-inline-code]:px-1.5 [&_.md-inline-code]:py-0.5 [&_.md-inline-code]:font-mono [&_.md-inline-code]:text-[0.88em] [&_.md-inline-code]:text-amber-200/90",children:n})}const b=_(y),f=b.filter(i=>i.level===2).map(i=>i.id);function L(){const[i,a]=x.useState(f[0]??"");return x.useEffect(()=>{const n=f.map(t=>document.getElementById(t)).filter(t=>t!==null);if(n.length===0)return;const r=new IntersectionObserver(t=>{var s;const u=t.filter(c=>c.isIntersecting).sort((c,k)=>k.intersectionRatio-c.intersectionRatio);(s=u[0])!=null&&s.target.id&&a(u[0].target.id)},{rootMargin:"-18% 0px -62% 0px",threshold:[0,.2,.5]});return n.forEach(t=>r.observe(t)),()=>r.disconnect()},[]),e.jsxs("div",{className:"teknik-dokuman-page min-h-screen bg-[#020203] text-zinc-100 overflow-x-hidden",children:[e.jsxs("div",{className:"pointer-events-none fixed inset-0 -z-10 overflow-hidden",children:[e.jsx("div",{className:"absolute top-0 left-1/4 h-[520px] w-[520px] rounded-full bg-purple-600/[0.07] blur-[140px]"}),e.jsx("div",{className:"absolute bottom-1/4 right-0 h-[400px] w-[400px] rounded-full bg-amber-500/[0.04] blur-[120px]"}),e.jsx("div",{className:"absolute top-1/2 left-0 h-[280px] w-[280px] rounded-full bg-indigo-500/[0.04] blur-[100px]"})]}),e.jsx("header",{className:"sticky top-0 z-30 border-b border-white/[0.06] bg-[#020203]/80 backdrop-blur-xl",children:e.jsxs("div",{className:"mx-auto max-w-6xl px-4 sm:px-6 py-3.5 flex items-center justify-between gap-4",children:[e.jsxs("a",{href:"/",className:"inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.03] px-3.5 py-2 text-xs font-mono text-zinc-400 hover:text-white hover:border-white/20 transition",children:[e.jsx(N,{className:"h-3.5 w-3.5"}),"Ana sayfa"]}),e.jsxs("div",{className:"flex items-center gap-2 font-mono text-[10px] uppercase tracking-[0.2em] text-zinc-500",children:[e.jsx(T,{className:"h-3.5 w-3.5 text-purple-400/80"}),"White Paper v1.1"]})]})}),e.jsx("div",{className:"mx-auto max-w-6xl px-4 sm:px-6 pb-20 pt-10 lg:pt-14",children:e.jsxs("div",{className:"lg:grid lg:grid-cols-[240px_minmax(0,900px)] lg:gap-12 lg:justify-center",children:[e.jsx("aside",{className:"hidden lg:block",children:e.jsxs("nav",{"aria-label":"İçindekiler",className:"sticky top-24 max-h-[calc(100vh-7rem)] overflow-y-auto pr-1",children:[e.jsx("p",{className:"font-mono text-[10px] uppercase tracking-[0.22em] text-zinc-500 mb-4",children:"İçindekiler"}),e.jsx("ul",{className:"space-y-0.5 border-l border-white/[0.08]",children:b.map(n=>{const r=n.level===2&&i===n.id;return e.jsx("li",{children:e.jsx("a",{href:`#${n.id}`,className:`block py-1.5 no-underline transition border-l-2 -ml-px ${n.level===3?"pl-6 text-xs text-zinc-500 hover:text-zinc-300":`pl-4 text-[13px] ${r?"text-white border-amber-400/70 font-medium":"text-zinc-500 hover:text-zinc-200 border-transparent"}`}`,children:n.text.replace(/^\d+\.\s*/,"")})},n.id)})})]})}),e.jsxs("article",{className:"w-full max-w-[900px] min-w-0 mx-auto lg:mx-0",children:[e.jsxs("div",{className:"mb-10 sm:mb-12",children:[e.jsx("span",{className:"inline-flex items-center gap-2 rounded-full border border-purple-500/25 bg-purple-500/[0.08] px-3.5 py-1.5 font-mono text-[10px] uppercase tracking-[0.18em] text-purple-300",children:"Zinesh Protocol"}),e.jsx("p",{className:"mt-6 font-mono text-xs text-zinc-500 tracking-wide",children:"White Paper · Sürüm 1.1 · Temmuz 2026"})]}),e.jsxs("nav",{"aria-label":"İçindekiler (mobil)",className:"lg:hidden mb-10 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5",children:[e.jsx("p",{className:"font-mono text-[10px] uppercase tracking-[0.2em] text-zinc-500 mb-3",children:"İçindekiler"}),e.jsx("ul",{className:"space-y-2 text-sm",children:b.filter(n=>n.level===2).map(n=>e.jsx("li",{children:e.jsx("a",{href:`#${n.id}`,className:"text-zinc-400 hover:text-white no-underline transition font-sans",children:n.text})},n.id))})]}),e.jsx("div",{className:"rounded-3xl border border-white/[0.06] bg-gradient-to-b from-white/[0.03] to-transparent p-6 sm:p-10 shadow-[0_24px_80px_rgba(0,0,0,0.35)]",children:e.jsx(S,{markdown:y})}),e.jsxs("footer",{className:"mt-12 pt-8 border-t border-white/[0.06] text-center",children:[e.jsx("p",{className:"font-sans text-sm text-zinc-500 leading-relaxed max-w-lg mx-auto",children:"Bu belge yatırım tavsiyesi değildir. Güncel üretim davranışı için kaynak kod ve API yanıtları esas alınır."}),e.jsx("a",{href:"/",className:"inline-flex mt-6 rounded-full bg-white px-6 py-3 text-sm font-bold text-black hover:bg-zinc-200 transition",children:"zinesh.com"})]})]})]})})]})}j.createRoot(document.getElementById("root")).render(e.jsx(x.StrictMode,{children:e.jsx(L,{})}));
