# Google Play MVP Checklist

Zinesh’i Google Play’e çıkarmadan önce tamamlanması gereken minimum işler.  
**Hedef:** Gerçek kullanıcı bir işi uçtan uca yapabilsin — yatır → eşleş → sözleşme → kilit → teslim → onay → çek.

**Durum özeti (Ağustos 2026):** Ürün mesajı ve konsol sadeleşmesi tamam; para döngüsü, sözleşme revizyonu, Android paketi ve mağaza uyumu eksik.

---

## Öncelik sırası

| Faz | Konu | Tahmini süre | Bloker? |
|-----|------|--------------|---------|
| **A** | Para döngüsü (yatır + çek) | 2–3 hafta | Evet |
| **B** | Sözleşme & emanet akışı | 1 hafta | Evet |
| **C** | İçerik & marka tutarlılığı | 2–3 gün | Hayır (ama inceleme riski) |
| **D** | Android paketi (TWA) | 1 hafta | Evet |
| **E** | Play Console & uyum | 3–5 gün | Evet |
| **F** | Kapalı test → yayın | 1–2 hafta | Evet |

---

## Faz A — Para döngüsü (P0)

Kullanıcı parayı sisteme girip iş bitince dışarı alabilmeli. Play’de “ödeme/emanet” kategorisinde bu olmadan yayın yapılmamalı.

### A1. Kart ile yatırma (iyzico canlı)

- [ ] `api/config.local.php` içinde iyzico **canlı** anahtarları (`sandbox => false`)
- [ ] `zinesh_payment_enabled()` true dönüyor (`api/tl_payment_lib.php`)
- [ ] Konsoldan test: 50+ TL yatır → bakiye artıyor → işlem geçmişinde görünüyor
- [ ] Başarısız ödeme / iptal senaryosu kullanıcıya anlaşılır mesaj veriyor
- [ ] `WalletCenter.tsx` “kart henüz aktif değil” uyarısı kalkmış olmalı

**Dosyalar:** `api/tl_payment_lib.php`, `api/payment.php`, `src/components/WalletCenter.tsx`, `src/lib/paymentApi.ts`

### A2. Havale yatırma (manuel onay)

- [ ] Sunucuda IBAN + banka adı + hesap sahibi tanımlı (`tl_havale` config)
- [ ] Kullanıcı havale bildirimi gönderiyor → admin/founder onaylıyor → bakiye artıyor
- [ ] Bekleyen havale durumu kullanıcıya görünür (şu an sessiz bekleme riski)
- [ ] Onay SLA’sı tanımlı (ör. 24 saat içinde) ve destek kanalı yazılı

**Dosyalar:** `api/tl_havale_lib.php`, `api/wallet_lib.php`, founder paneli

### A3. IBAN ile çekim (minimum: manuel onaylı)

- [ ] API: çekim talebi oluşturma (`withdrawals.json` akışı)
- [ ] Kullanıcı IBAN + tutar girer → “işleniyor” durumu
- [ ] Admin onayı → bakiye düşer → kullanıcıya bildirim
- [ ] Minimum çekim tutarı ve günlük limit belirlenmiş
- [ ] `WalletCenter.tsx` “Faz 2” placeholder kaldırılmış, gerçek form var

**Dosyalar:** `api/wallet.php`, `api/wallet_lib.php`, `src/components/WalletCenter.tsx`

### A4. Uçtan uca para testi

- [ ] Senaryo 1: Kart yatır → emanet kilitle → çift onay → satıcı çeker
- [ ] Senaryo 2: Havale yatır → itiraz → hakem kararı → bakiye doğru
- [ ] Senaryo 3: Yetersiz bakiye → net hata mesajı
- [ ] Tüm adımlar mobil tarayıcıda (Chrome Android) sorunsuz

**Kabul kriteri:** 3 farklı test hesabıyla, gerçek (küçük) tutarlarla, destek müdahalesi olmadan tamamlanır.

---

## Faz B — Sözleşme & emanet akışı (P0)

### B1. Sözleşme revizyonu

- [ ] Satıcı “revize iste” diyebilir (işi sıfırdan başlatmadan)
- [ ] Yeni teklif `negotiating` / `terms_pending` durumuna döner
- [ ] Sohbet geçmişi korunur; eski teklif metni okunabilir kalır

**Dosyalar:** `api/escrow_room_lib.php`, `src/components/EscrowRoomPanel.tsx`

### B2. Kilit sonrası sözleşme dondurma

- [ ] `accept_terms` sonrası `description` alanı API’de değiştirilemez
- [ ] UI’da sözleşme salt okunur blok olarak gösterilir
- [ ] İtiraz ekranında donmuş metin referans olarak görünür

### B3. Satıcı da teklif önerebilsin (opsiyonel ama önerilir)

- [ ] `propose_terms` yalnızca `employer` değil, karşı taraf da önerebilsin VEYA
- [ ] Rol bazlı: hizmet veren fiyat/kapsam önerir, hizmet alan onaylar

**Mevcut kısıt:** `api/escrow_room_lib.php` — “Anlaşma tutarını yalnızca işveren önerebilir.”

### B4. Kabul öncesi özet ekranı

- [ ] Tutar + sözleşme metni + komisyon bilgisi tek ekranda
- [ ] “Kabul ediyorum” açık onay (checkbox veya ikinci buton)

### B5. Emanet akışı smoke testi

- [ ] ID ile eşleş (4–6 haneli sayı, eski `ZN-SH-DUAL-*` formatı da kabul)
- [ ] Min 40 karakter sözleşme zorunlu
- [ ] Kilit → teslim → çift onay → bakiye transferi
- [ ] İtiraz açma ve durum etiketleri doğru (`escrowRoomStatusLabel`)

---

## Faz C — İçerik & marka tutarlılığı (P1)

Play incelemecisi ve kullanıcı aynı hikâyeyi görmeli: **TL emanet, yazılı sözleşme** — kripto/Web3 değil.

### C1. Blog & statik sayfalar

- [ ] `/blog/usdt-ile-guvenli-is/` URL’si yönlendirme veya slug değişimi (`guvenli-odeme` vb.)
- [ ] `/blog/web3-ve-gercek-ekonomi/` gözden geçir veya arşivle
- [ ] `public/kurucu/`, `public/nedir/` blockchain vurgusunu sadeleştir
- [ ] `sitemap.xml` güncel URL listesi

**Script:** `scripts/generate-static-pages.mjs`

### C2. Uygulama içi kalıntılar

- [ ] `AiAssistant.tsx`, `ConsoleSummaryPanel.tsx`, `campaignApi.ts` — kullanılmıyorsa bundle’dan çıkar veya USDT metinlerini temizle
- [ ] `plainLanguage.ts` — `connectWallet` gibi eski terimler → “Hesabına giriş” vb.
- [ ] `productMode.ts` — `WEB3_ENABLED = false` dokümante; yanlışlıkla açılmasın

### C3. Yasal metinler (uygulama içi erişim)

- [ ] Gizlilik politikası (`/gizlilik/`) konsol ayarlarından açılır
- [ ] Kullanım koşulları (`/kullanim-kosullari/`)
- [ ] Komisyon & hakemlik (`/komisyonlar/`, `/hakemlik/`)
- [ ] Footer’daki “yatırım ürünü / kripto borsası değildir” metni Play açıklamasıyla uyumlu

---

## Faz D — Android paketi (P0)

Şu an repoda native Android projesi yok. Önerilen yol: **TWA (Trusted Web Activity)** — `app.zinesh.com`’u tam ekran açar.

### D1. TWA projesi

- [ ] Bubblewrap veya Android Studio TWA şablonu oluştur
- [ ] `assetlinks.json` → `https://app.zinesh.com/.well-known/assetlinks.json`
- [ ] Paket adı kararlaştır: örn. `com.zinesh.app`
- [ ] İmzalı `.aab` üret (Play upload key)

### D2. Mobil UX

- [ ] 360px genişlikte konsol panelleri taşmıyor
- [ ] Klavye açıkken form alanları görünür (özellikle sözleşme textarea)
- [ ] Android geri tuşu: oda içi → liste → konsol (mantıklı stack)
- [ ] `viewport` + `theme-color` + splash screen

### D3. OAuth / oturum

- [ ] Google Sign-In Android client ID + SHA-1/SHA-256 fingerprint
- [ ] TWA içinde cookie/session kalıcılığı test edildi
- [ ] Çıkış yap → tekrar giriş sorunsuz

### D4. PWA tamamlama

- [ ] `site.webmanifest` — `start_url` app subdomain’e işaret etsin (`/konsol` veya `/`)
- [ ] Maskable ikon 512×512 doğrulandı
- [ ] Offline durumda anlamlı hata (boş sayfa değil)

---

## Faz E — Play Console & uyum (P0)

### E1. Mağaza listesi

- [ ] Uygulama adı: **Zinesh — Güvenli Ödeme**
- [ ] Kısa açıklama (80 karakter): TL emanet, yazılı sözleşme vurgusu
- [ ] Uzun açıklama: ne yapar / ne yapmaz (borsa değil, yatırım değil)
- [ ] Ekran görüntüleri: ana sayfa, emanet başlat, sözleşme, kasa (min 4, telefon)
- [ ] Feature graphic 1024×500
- [ ] Gizlilik politikası URL: `https://www.zinesh.com/gizlilik/`

### E2. Data safety formu

- [ ] Toplanan veriler: e-posta, ad, işlem geçmişi, KYC (varsa)
- [ ] Veri şifreleme (HTTPS) beyanı
- [ ] Veri silme talebi süreci

### E3. Finans / politika beyanları

- [ ] “Finansal özellikler” — evet (emanet / ödeme aracılığı)
- [ ] Hedef kitle: 18+ (para işlemleri)
- [ ] İçerik derecelendirmesi anketi
- [ ] Türkiye’de para tutma modeli için hukuk notu (lisans gereksinimi değerlendirmesi)

> **Risk:** Google, lisanssız ödeme/emanet uygulamalarını reddedebilir veya ek belge isteyebilir. Kapalı testte erken geri bildirim alın.

### E4. Destek kanalı

- [ ] Destek e-postası Play Console’da tanımlı
- [ ] Uygulama içi “Yardım / Destek” linki
- [ ] İtiraz ve havale gecikmesi için SLA metni

---

## Faz F — Kapalı test → yayın (P0)

### F1. Kapalı test (min 20 tester)

- [ ] Play Console Internal / Closed track’e `.aab` yükle
- [ ] 20+ test kullanıcısı davet
- [ ] Gerçek senaryo listesi paylaş (aşağıdaki test planı)
- [ ] Crash ve 1 yıldız geri bildirimleri topla, düzelt

### F2. Açık beta (opsiyonel)

- [ ] 100 kullanıcıya kadar açık beta
- [ ] Havale onay ve hakemlik SLA’sı operasyonel

### F3. Production yayın

- [ ] Tüm P0 maddeleri yeşil
- [ ] Son deploy: frontend + API aynı sürüm
- [ ] Rollback planı (önceki `dist` + API snapshot)

---

## Test planı (kapalı test için paylaş)

Her tester şunları dener:

1. **Kayıt / giriş** — e-posta veya Google
2. **Üye ID’yi kopyala** — karşı tarafa gönder (sayı formatı, örn. `22595`)
3. **Eşleş** — rol seç (hizmet alan / veren)
4. **Sözleşme yaz** — en az 40 karakter, tutar belirle
5. **Kabul et** — bakiye kilitlensin
6. **Teslim + çift onay** — ödeme satıcıya geçsin
7. **Çekim** — IBAN’a talep oluştur
8. **İtiraz senaryosu** (opsiyonel) — ayrı test odası

**Başarı:** 8 adımın 7’si destek olmadan tamamlanır.

---

## “Play’e hazırız” tanımı

Aşağıdakilerin **hepsi** true ise production yayın yapılabilir:

- [ ] Para yatırma (kart veya havale) canlıda çalışıyor
- [ ] Para çekme talebi oluşturulup tamamlanabiliyor
- [ ] Emanet uçtan uca (eşleş → sözleşme → kilit → onay) mobilde test edildi
- [ ] İmzalı `.aab` Play Console’a yüklendi
- [ ] Mağaza listesi + gizlilik + data safety tamam
- [ ] Kapalı testte ≥20 kullanıcı, kritik bug yok
- [ ] Blog/uygulama mesajı TL emanet ile tutarlı; USDT/Web3 ana akışta yok

---

## Hızlı referans — mevcut kod durumu

| Özellik | Durum | Konum |
|---------|--------|--------|
| TL modu | Açık | `src/lib/productMode.ts` |
| Web3 / USDT | Kapalı | `WEB3_ENABLED = false` |
| Kart yatırma | Config’e bağlı | `api/tl_payment_lib.php` |
| Havale | Manuel onay | `api/tl_havale_lib.php` |
| Çekim | Placeholder “Faz 2” | `WalletCenter.tsx` ~517 |
| Sözleşme min 40 char | Var | `escrow_room_lib.php`, `EscrowRoomPanel.tsx` |
| Teklif sadece işveren | Kısıtlı | `escrow_room_lib.php` ~350 |
| Üye ID sayısal | Var | `src/lib/memberTicket.ts` |
| Android paketi | Yok | — |
| PWA manifest | Var | `public/site.webmanifest` |

---

## Sonraki adım önerisi

1. **Bu hafta:** A3 (çekim) + A1 (iyzico canlı) — para döngüsünü kapat  
2. **Gelecek hafta:** B1–B2 (revize + dondurma) + D1 (TWA iskelet)  
3. **Paralel:** C1 blog temizliği + E1 mağaza görselleri taslağı  
4. **3. hafta:** Kapalı test (F1)

Sorular veya öncelik değişikliği için bu dosyayı güncelleyin.
