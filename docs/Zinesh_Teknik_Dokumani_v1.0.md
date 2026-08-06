# Zinesh Teknik Dokümanı

**White Paper · Sürüm 1.1**  
**Temmuz 2026 · TL emanet üretim referansı**

---

> Tanımadığın biriyle iş yapmak, internetin en eski ve en pahalı sorunlarından biridir. Zinesh bu sorunu spekülasyonla değil; **kurallar, emanet kasası ve kanıt** ile çözmeyi hedefler.

---

## 1. Önsöz

İnternet bilgiyi taşıdı. Ödeme ağları parayı taşıdı. Ama **güveni** taşımadı.

Freelance işler, uzaktan hizmetler ve küçük ticaret hâlâ aynı riskle yüz yüze: *“Önce ben ödersem kaybederim; önce o yaparsa o kaybeder.”* Platformlar bu boşluğu aracılık komisyonu ve opak kurallarla doldurdu. Zinesh farklı bir yere bakar: güvenin bir marka vaadi değil, **doğrulanabilir bir protokol davranışı** olması gerektiğine inanır.

Bu belge üç katmanı bir arada sunar:

- **Felsefe** — protokolün değişmez niyeti  
- **v1.1 gerçeği** — bugün üretimde çalışan TL emanet sistemi  
- **Hedef tasarım** — henüz tamamlanmamış, yol haritasındaki mekanizmalar  

Okuyucu, hangi cümlenin hangi katmana ait olduğunu metin içinde açıkça görecektir. Yatırım vaadi yoktur; abartı yoktur.

---

## 2. Problem: Güvenin Eksik Protokolü

### 2.1 İkili güvensizlik

Hizmet ekonomisinde iki taraf da haklı olarak şüphe duyar. Alıcı ödemeyi önceden yaparsa teslimat riski taşır; satıcı önce çalışırsa tahsilat riski taşır. Klasik çözümler — referans, platform itibarı, mahkeme — yavaş, pahalı veya sınır ötesi işlerde erişilemez kalır.

### 2.2 Zinesh’in cevabı

Zinesh şunu önerir:

1. **İş bedeli TL olarak emanet kasasında kilitlenir** — para ne alıcıda ne satıcıda serbest dolaşır; anlaşmaya bağlıdır.  
2. **Uyuşmazlıkta bağımsız hakemler devreye girer** (hedef tasarım) — karar kanıtlara dayanır.  
3. **Güven puanı (TrustScore) zaman içinde kazanılır** — parayla satın alınamaz.

Bu üçlü, bir “kripto projesi” anlatısından önce **ticaret güvenliği** anlatısıdır.

---

## 3. Protokol İlkeleri

Aşağıdaki ilkeler Zinesh’in anayasasıdır. Teknik kararlar bunlara aykırı olamaz.

### 3.1 Güven satın alınamaz

Güven puanı ve hakemlik geçmişi **zaman ve davranışla** kazanılır. Sahte hesaplar, yapay iş döngüleri ve yalnızca bekleme ile simüle edilen geçmiş anlamlı katkı sayılmaz.

### 3.2 TL iş parası

İş ödemeleri ve teminatlar **Türk Lirası (₺)** cinsindendir. Site cüzdanında TL bakiyesi tutulur; emanet oluşturulduğunda tutar kasada kilitlenir. Zinesh bir yatırım ürünü veya kripto borsası değildir.

### 3.3 Şeffaflık ve dürüst ticaret

Protokol, gizli ceza tuzağı veya getiri vaadi üzerine kurulmamıştır. Kullanıcılar iş kurallarını ve komisyonları önceden bilir.

### 3.4 İnsan hakemleri, makine değil

Uyuşmazlık kararları hedef tasarımda **insan hakemleri** tarafından verilir. Yapay zeka modelleri nihai karar veremez.

---

## 4. Sistem Mimarisi (v1.1)

v1.1 üretim ortamı merkezi sunucu mimarisidir. Bu bir zayıflık gizlemez; aşamalı olgunlaşma stratejisidir.

### 4.1 Bileşenler

```
[Kullanıcı] ──HTTPS──► Nginx ──► PHP-FPM (api/*.php)
                              │
                              ▼
                    /www/server/zinesh-data/*.json
                              │
                              ▼
                    Yedekleme (rclone → Google Drive)
```

| Katman | Görev |
|--------|-------|
| SPA (React) | Kullanıcı arayüzü, emanet akışları, cüzdan |
| PHP API | Kimlik, cüzdan, kampanya, emanet mutasyonları |
| JSON veri | `users.json`, `sessions.json`, emanet kayıtları |
| Nginx | TLS, güvenlik başlıkları, hassas dosya engeli |

### 4.2 Oturum ve kimlik

Oturum token’ları sunucuda `sessions.json` içinde tutulur. Giriş: e-posta + şifre veya Google OAuth (GIS). Kurucu hesaplarında isteğe bağlı TOTP (Google Authenticator) devrededir. Başarısız giriş denemelerinde e-posta doğrulama, paralel giriş kilidi ve spam koruması uygulanır.

### 4.3 Atomik yazım

Kritik bakiye değişiklikleri dosya kilidi altında yapılır. Paralel isteklerde çift harcama ve tutarsız bakiye riski azaltılır.

---

## 5. Emanet ve İş Akışı

### 5.1 Emanet modeli (v1.1)

- Alıcı veya işveren iş bedelini **TL** olarak site cüzdanına yatırır.  
- Emanet oluşturulduğunda tutar **kilitlenir** (`escrowBalance`).  
- Çekilebilir bakiye: `tlBalance − escrowBalance`.  
- İş tamamlandığında komisyon düşülür; kalan tutar karşı tarafa aktarılır.

### 5.2 Komisyon yapısı

Tamamlanan emanetlerde **%5** protokol komisyonu uygulanır. Bu pay operasyon ve platform sürdürülebilirliği için ayrılır.

| Pay | Açıklama |
|-----|----------|
| Protokol komisyonu | %5 — emanet serbest bırakıldığında |

Detaylı dağılım: [Komisyonlar](/komisyonlar/).

### 5.3 Ödeme yöntemleri (v1.1)

| Yöntem | Durum |
|--------|-------|
| Havale / EFT bildirimi | Yapılandırıldığında aktif |
| Kart (iyzico) | Yapılandırıldığında aktif |
| IBAN çekim | Faz 2 — planlanıyor |

### 5.4 Hedef tahkim (henüz tam üretimde değil)

Hedef tasarımda uyuşmazlık **bağımsız hakemler** tarafından, kanıt dosyalarıyla çözülür. v1.1’de emanet oluşturma, kilitleme ve serbest bırakma aktiftir; tam otomatik hakemlik paneli yol haritasındadır.

---

## 6. Kurucu Üye Programı (Erken Erişim)

| Parametre | Değer |
|-----------|-------|
| Kontenjan | 500 kullanıcı |
| Görevler | E-posta doğrulama, TL yatırma, KYC, 3 referans, ilk emanet |

Kontenjan dolduğunda program sona erer. Amaç erken kullanıcıları toplamak ve gerçek emanet akışını test etmektir; token veya kripto ödülü yoktur.

---

## 7. Güven Puanı (TrustScore)

TrustScore (0–100), protokol içi davranışın özetidir. Yeni kullanıcı **50** ile başlar. Güven puanı **parayla satın alınamaz**.

Hedef tasarımda skor; tamamlanan emanetler, hakemlik performansı ve haksız taraf cezalarıyla güncellenir. Her emanet kaydı güven profilinde görüntülenebilir (gizlilik ayarına bağlı).

---

## 8. Güvenlik ve Operasyon

### 8.1 Sunucu sertleştirme

- Nginx ile hassas PHP/JSON dosyalarına doğrudan erişim engeli  
- CSP ve güvenlik başlıkları  
- IP tabanlı rate limit  
- Denetim günlüğü (`audit_log.json`)

### 8.2 Yedekleme

Günde üç kez otomatik yedek; bulut depoda saklama. Geri yükleme prosedürü sunucu yöneticisi tarafından uygulanır.

### 8.3 v1.1 şeffaflık sınırı

Bakiyeler ve emanet kayıtları merkezi muhasebededir. Kullanıcılar kendi işlem geçmişlerini konsoldan görür. Halka açık blokzincir doğrulaması yoktur; üretim yolu PHP + JSON’dur.

---

## 9. API Özeti

| Uç | Dosya | Açıklama |
|----|-------|----------|
| Kimlik | `auth.php` | Kayıt, giriş, Google OAuth, oturum, şifre sıfırlama |
| Cüzdan | `wallet.php` | TL bakiye, emanet oluşturma/serbest bırakma, havale bildirimi |
| Ödeme | `payment.php` | Kart ile TL yatırma (iyzico) |
| Kampanya | `campaign_public.php` | Erken erişim durumu |
| Güven profili | `trust_profile.php` | Emanet geçmişi, gizlilik |

Tüm yazma istekleri `POST` + JSON gövde ile yapılır.

---

## 10. Yol Haritası

| Aşama | Odak |
|-------|------|
| v1.1 (mevcut) | TL emanet, e-posta/Google giriş, havale/kart yatırma, güven profili |
| v1.x | IBAN çekim, hakemlik paneli, TrustScore otomasyonu |
| v2+ | Gelişmiş tahkim, çok taraflı emanet şablonları |

Yol haritası taahhüt değil; öncelik sırasıdır. Özellikler üretim kanıtı olmadan “aktif” ilan edilmez.

---

## 11. Yasal Bilgilendirme

Zinesh bir yatırım ürünü, kripto borsası veya getiri vaadi sunan platform değildir. Tanımadığınız kişilerle yaptığınız anlaşmalarda paranız emanet kasasında tutulur; iş bitince siz onaylarsınız. Anlaşmazlıkta hakem süreçleri devreye girer (hedef tasarım).

---

## 12. Sürüm Geçmişi

| Sürüm | Tarih | Not |
|-------|-------|-----|
| 1.0 | Haziran 2026 | İlk white paper |
| 1.1 | Temmuz 2026 | TL emanet odaklı revizyon; kripto/token ekonomisi kaldırıldı |

---

*© 2026 Zinesh Protocol — Kurucu: Yasin Karademir*
