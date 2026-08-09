# Trust Intelligence Architecture

> **Bu doküman bir implementasyon planı değildir.**
>
> Bu doküman, Zinesh Trust Intelligence katmanının uzun vadeli mimari vizyonunu tanımlar.
>
> Production implementasyonları ayrı sprintlerde gerçekleştirilecektir.

**Versiyon:** 0.1 (mimari vizyon)  
**Tarih:** 2026-08-07  
**Sprint:** S2 — Trust Intelligence Architecture (tasarım only)  
**Stable Core:** Escrow Engine (`escrow_room_lib.php`, `wallet_lib.php`) — dokunulmaz

---

## 1. Amaç

### Trust Intelligence nedir?

**Trust Intelligence**, Zinesh escrow platformunda gerçekleşen işlemlerin **geçmiş olay kayıtlarından** türetilen, **açıklanabilir** ve **salt okunur** davranış gözlemlerini üreten üst katmandır. Amaç, tarafların ve operatörlerin karar vermeden önce **bağlamsal farkındalık** kazanmasıdır — ödeme, dispute veya state geçişi **değil**, bilgi.

### Ne değildir?

| Trust Intelligence **değildir** | Açıklama |
|-----------------------------------|----------|
| Escrow motoru | Para kilitleme, settlement, recovery, dispute çözümü yapmaz |
| Karar motoru | İşlemi durdurmaz, kullanıcıyı engellemez, ceza vermez |
| Etiketleme sistemi | "Dolandırıcı", "güvenilmez", "iyi kullanıcı" gibi hüküm üretmez |
| Black-box skor | Açıklanamayan tek sayılı "güven puanı" değildir |
| Hukuki otorite | Sözleşme yorumu veya hukuki karar vermez |

### Bu katmanın amacı

1. **Şeffaflık** — Taraflar ne olduğunu, ne sıklıkla olduğunu görebilir.
2. **Erken uyarı (bilgi)** — Eksik madde, yüksek revizyon, dispute eğilimi gibi *gözlemler* sunar; kararı kullanıcı verir.
3. **Ölçeklenebilir güven** — İnsan hakemi ve escrow kuralları çekirdekte kalır; intelligence katmanı ölçekte bağlam sağlar.
4. **AI için güvenli zemin** — Copilot, ham event atlamadan, metric ve signal üzerinden konuşur.

### Escrow motorundan neden ayrı tutulmalıdır?

Escrow Engine artık **Stable Core**'dur (S1/S1.1 sonrası). Settlement, recovery ve wallet mutasyonları invariant-governed kritik yollardır. Trust Intelligence:

- **Yan etki üretmemeli** — Okuma hatası escrow state'ini bozmamalı.
- **Bağımsız evrilebilmeli** — Yeni sinyal, yeni metrik, yeni AI modeli çekirdek release döngüsünü bloke etmemeli.
- **İnkâr edilebilir olmalı** — Katman kapalıyken escrow normal çalışır.
- **Regülasyon ve etik** — Davranış gözlemi ile para hareketi aynı kod yolunda olmamalı; "AI ödedi" sınıfı riski sıfırlanmalı.

**Temel kural:** Intelligence **gözlemler**; Engine **taahhütleri yerine getirir**.

---

## 2. Bugünkü Mimari

Mevcut production katmanları (v0.1) aşağıdaki sırayla çalışır. Hepsi escrow çekirdeğinin **üstünde** ve çekirdeğe **yazmaz** (memory emit hariç — o ayrı bounded context).

```
Escrow Engine (Stable Core)
        ↓
    Events
        ↓
    Timeline
        ↓
   AI Context
        ↓
  Trust Metrics
        ↓
   Actor Trust
```

### Escrow Engine

**Görev:** Oda state machine, wallet settlement, dispute, recovery, locking.  
**Dosyalar:** `escrow_room_lib.php`, `wallet_lib.php` (ve doğrudan mutasyon yapan sınırlı lib'ler).  
**Özellik:** Tek gerçek kaynak (source of truth) para ve oda durumu için. S1 sonrası invariant-hardened.

### Events

**Görev:** Domain olaylarının append-only kaydı (`zinesh_domain_events` / escrow memory emit).  
**Kaynak:** `escrow_memory_lib.php` — `terms_proposed`, `settlement_completed`, `dispute_opened` vb.  
**Özellik:** Escrow flow tarafından tetiklenir; intelligence katmanı **üretmez**, **okur**.

### Timeline

**Görev:** Oda bazlı insan-okunur olay dizisi (`zinesh_escrow_room_timeline`).  
**Özellik:** Events'ten türetilir; UI ve AI Context için kronolojik özet.

### AI Context

**Görev:** Tek oda için sanitize edilmiş bağlam paketi — sözleşme versiyonları, timeline özeti, davranış istatistikleri (`ai_context_lib.php`).  
**Özellik:** PII ve iç metadata sızdırmaz; recovery tetiklemez (salt okuma snapshot).

### Trust Metrics

**Görev:** **Oda bazlı** deterministik metrikler (`trust_intelligence_lib.php` v0.1 + `trust_metrics_lib.php`).  
**Örnekler:** negotiation_rounds, counter_offer_count, successful_settlement, dispute_count, cooperation/conflict/reliability skorları (istatistiksel, oda bağlamında).  
**Özellik:** Salt okunur; cache/yazma yok.

### Actor Trust

**Görev:** **Kullanıcı bazlı** çoklu oda agregasyonu (`actor_trust_lib.php` v0.1).  
**Örnekler:** transactions, successful_settlements, disputes, ortalama completion time, ortalama negotiation rounds.  
**Özellik:** Salt okunur; karar etiketi vermez; yalnızca kendi verisine erişim (API auth).

### Bugünkü boşluk

`trust_intelligence_lib.php` v0.1 **metrik** üretir; henüz tam anlamıyla **signal** katmanı (davranış gözlemi cümleleri), **Risk Engine**, **AI Copilot** ve **Enterprise Observability** yoktur. S2 bu boşluğun mimari vizyonunu tanımlar.

---

## 3. Gelecek Mimarisi

```
Escrow Engine          ← Stable Core (dokunulmaz)
        ↓
    Events             ← Append-only domain log
        ↓
    Timeline           ← Oda kronolojisi
        ↓
   AI Context          ← Sanitize bağlam paketi
        ↓
  Trust Metrics        ← Deterministik sayılar
        ↓
   Actor Trust         ← Kullanıcı agregasyonu
        ↓
Trust Intelligence     ← Davranış sinyalleri (gözlem)
        ↓
   Risk Engine         ← İstatistiksel risk gözlemleri
        ↓
   AI Copilot          ← Açıklama ve öneri (LLM)
        ↓
Enterprise Observability  ← Operatör / B2B görünürlük
```

### Katman sorumlulukları

| Katman | Sorumluluk | Yazma | Karar |
|--------|------------|-------|-------|
| Escrow Engine | State, para, dispute | Evet | Kullanıcı + kurallar |
| Events | Olay kaydı | Append (engine) | — |
| Timeline | Görüntüleme | Hayır | — |
| AI Context | Bağlam derleme | Hayır | — |
| Trust Metrics | Deterministik metrik | Hayır | — |
| Actor Trust | Actor agregasyon | Hayır | — |
| **Trust Intelligence** | Davranış **sinyalleri** (açıklanabilir gözlem) | Hayır | — |
| **Risk Engine** | Risk **gözlemleri** (istatistik) | Hayır | — |
| **AI Copilot** | Doğal dil açıklama / öneri | Hayır | Kullanıcı |
| **Enterprise Observability** | Portföy / organizasyon görünürlüğü | Hayır | Operatör |

### Neden ayrılmıştır?

1. **Metrics ≠ Signals ≠ Risk ≠ Narration** — Her katman bir öncekinin çıktısını tüketir; karışım debug ve denetimi imkânsızlaştırır.
2. **LLM en üstte** — AI Copilot ham veriye değil, sanitize metric/signal/risk paketine konuşur.
3. **Enterprise ayrı tüketici** — B2B dashboard, escrow çekirdeğinden ve bireysel AI Context'ten bağımsız ölçeklenir.
4. **Kapatılabilirlik** — Copilot kapalıyken metrics ve signals çalışmaya devam edebilir.

---

## 4. Trust Intelligence

Trust Intelligence katmanı, Trust Metrics ve Actor Trust üzerinden **davranış sinyalleri** üretir. Sinyal = sayı + kısa gözlem cümlesi + kanıt referansı (event type, sayım, zaman aralığı).

### Üretilecek sinyal örnekleri

| Sinyal alanı | Örnek gözlem (etiket değil) |
|--------------|----------------------------|
| Müzakere | "Bu odada 4 counter offer kaydı var." |
| Müzakere | "Şart değişikliği talebi 3 kez oluştu." |
| Uzlaşma | "İlk tekliften kabul'e 2 saat geçti." |
| Dispute | "Bu actor geçmiş 10 işin 2'sinde dispute açmış." |
| Teslim | "Tamamlama onayı, kilitlenmeden 14 gün sonra verildi." |
| Deneyim | "Bu actor için platformdaki ilk tamamlanan iş." |
| Düzenlilik | "Son 90 günde 6 başarılı settlement." |
| Revizyon | "Sözleşme versiyon sayısı: 5 (ortalama 2.1)." |
| Müzakere süresi | "Müzakere süresi bu actor ortalamasının altında." |

### Sinyal kuralları

Trust Intelligence:

- ✅ **Yalnızca gözlem üretir** — sayı, oran, süre, frekans.
- ❌ **Etiketleme yapmaz** — "sürekli şart değiştiriyor" bir *gözlem cümlesi* olabilir; "sorunlu kullanıcı" olamaz.
- ❌ **Ceza vermez** — escrow, wallet veya erişim etkilemez.
- ❌ **Hüküm vermez** — niyet, karakter veya gelecek tahmini iddiası yok.

Her sinyal **Evidence Before Intelligence** zincirine bağlıdır: hangi event'lerden, hangi metric'ten türetildiği açıkça belirtilir.

---

## 5. Risk Engine

### Risk Score üretmeli mi?

**Evet — ancak yalnızca istatistiksel gözlem olarak.**

Risk Engine, Trust Intelligence sinyallerini ve Actor Trust metriklerini birleştirerek **bağlamsal risk gözlemleri** üretir. Çıktı bir **karar değil**, **açıklanabilir istatistik**tir.

### Hangi sinyaller kullanılmalı?

| Kaynak | Örnek girdi |
|--------|-------------|
| Trust Metrics (oda) | negotiation_rounds, dispute_count, settlement_failed |
| Actor Trust (kullanıcı) | disputes / transactions oranı, avg_completion_time |
| Trust Intelligence sinyalleri | yüksek revizyon, ilk işlem, hızlı uzlaşma |
| Bağlam | oda tutarı, süre, dispute durumu (mevcut state — okuma only) |

Risk Engine çıktısı örnek format (vizyon):

- `observation`: "Bu oda tutarı, actor'ın geçmiş ortalama iş tutarının 3.2 katı."
- `basis`: `actor_trust.metrics.history` + `room.agreedAmountTry`
- `confidence`: düşük / orta / yüksek (veri yeterliliğine göre)

### İşlemi durdurabilir mi?

**Hayır.**

Risk Engine:

- ❌ Settlement başlatamaz veya durduramaz
- ❌ Dispute sonucu öneremez (hukuki/karar niteliğinde)
- ❌ Kullanıcıyı engelleyemez
- ✅ UI, Copilot ve Enterprise dashboard'a **bilgi** sağlar

**Risk Score = karar değil, istatistiksel gözlem.**

---

## 6. AI Copilot

AI Copilot, sanitize edilmiş AI Context + Trust Metrics + Trust Intelligence sinyalleri + Risk gözlemlerini doğal dilde **açıklar** ve **öneride bulunur**. Son karar her zaman kullanıcıdadır.

### Örnek yardım senaryoları

| Durum | Copilot çıktısı (örnek) |
|-------|-------------------------|
| Eksik madde | "Sözleşmede teslim tarihi belirtilmemiş." |
| Eksik madde | "Garanti maddesi tanımlı değil." |
| Müzakere | "Taraflar bu odada üç kez şart değiştirdi." |
| Geçmiş | "Bu kullanıcı geçmiş işlemlerinde ortalama iki revizyon yaptı." |
| Bağlam | "Benzer tutar aralığındaki işlerde dispute oranı %18 idi (platform ortalaması)." |
| Risk bilgisi | "İlk işlem; geçmiş actor metrikleri sınırlı." |

### Copilot sınırları

- ✅ Açıklar, özetler, eksikleri gösterir, öneride bulunur
- ❌ Karar vermez, ödeme serbest bırakmaz, sözleşmeyi değiştirmez
- ❌ "Kabul et / reddet" talimatı vermez — yalnızca "şunu kontrol etmenizi öneririm" düzeyinde

---

## 7. AI Güvenlik Prensipleri

AI aşağıdakileri **ASLA** yapmaz:

| Yasak | Gerekçe |
|-------|---------|
| Ödeme serbest bırakmaz | Para mutasyonu yalnızca Escrow Engine |
| Dispute sonucu belirlemez | Hakemlik insan/operasyonel süreç |
| Trust score değiştirmez | Skorlar deterministik veya gözlem; LLM yazmaz |
| Kullanıcıyı cezalandırır / engeller | Erişim ve ceza escrow/admin politikası |
| Sözleşmeyi değiştirir | Mutasyon kullanıcı onayı + engine |
| Hukuki karar verir | Platform hukuki otorite değildir |

AI yalnızca:

- Açıklar
- Özetler
- Risk sinyali **açıklaması** sunar (üretimi Risk Engine / Intelligence)
- Eksikleri gösterir
- Öneride bulunur

**Son karar daima kullanıcıya aittir.**

---

## 8. Trust Intelligence'ın Sınırları

Trust Intelligence **yapmaz**:

| Sınır | Açıklama |
|-------|----------|
| Kişilik analizi | Karakter, niyet, psikolojik profil yok |
| Niyet okuma | "Bunu bilerek yaptı" iddiası yok |
| Dolandırıcı etiketi | Binary etiket veya kara liste önerisi yok |
| İyi/kötü kullanıcı kararı | Normatif hüküm yok |
| Gelecek tahmini iddiası | "Bundan sonra şunu yapar" yok — yalnızca geçmiş frekans |

Trust Intelligence **yapar**:

- Geçmiş olaylardan **istatistiksel gözlem**
- Her çıktı için **açıklanabilir kanıt zinciri**
- Event timeline'a kadar **izlenebilirlik**

**İzlenebilirlik kuralı:** Her sinyal, tıklandığında (veya API'de `basis` alanında) ilgili event type listesi ve metric snapshot'ına referans verebilmelidir.

---

## 9. Tasarım İlkeleri

### Explainable AI

Her intelligence çıktısı insan tarafından doğrulanabilir olmalıdır. LLM çıktısı, deterministic metric ve event referansı olmadan tek başına "gerçek" sayılmaz.

### Read Only

Trust Intelligence stack'i (Metrics → Intelligence → Risk → Copilot prompt builder) escrow state, wallet ve treasury'ye **yazmaz**. Okuma hataları sessizce boş paket döner; engine'i etkilemez.

### Event Sourcing

Gerçek kaynak domain event'lerdir. Metric ve signal event'lerin türevidir; event silinmeden metric yeniden hesaplanabilir (replay vizyonu).

### Deterministic Metrics

Aynı event seti + aynı room snapshot → aynı metric. LLM non-deterministic katmandır ve metric'i **değiştirmez**.

### Privacy First

- Actor Trust yalnızca self-access (mevcut v0.1 politikası korunur).
- AI Context PII sızdırmaz (mevcut sanitize kuralları genişletilir, gevşetilmez).
- Enterprise Observability organizasyon kapsamıyla sınırlı; bireysel profil satışı vizyonu yok.

### Human in Control

Hiçbir intelligence çıktısı otomatik aksiyon tetiklemez. UI'da "bilgi kartı" ve "öneri" — varsayılan kapalı veya onay gerektiren aksiyonlar engine'de kalır.

### Evidence Before Intelligence

Zorunlu türetim sırası:

```
Event (ham domain olayı)
    ↓
Metric (deterministik sayı)
    ↓
Signal (davranış gözlemi)
    ↓
Risk Observation (istatistiksel bağlam)
    ↓
AI Yorumu (doğal dil — en son)
```

**AI hiçbir zaman ham event'i atlayarak çıktı üretmez.**

Copilot prompt'u yalnızca sanitize edilmiş metric, signal ve risk paketini alır; ham `users.json`, wallet bakiyesi veya iç admin notları almaz.

---

## 10. Roadmap

Aşağıdaki sprint sırası **mimari vizyon** içindir. Her sprint ayrı implementasyon planı, test ve release notu gerektirir. Escrow Stable Core'a dokunulmaz.

```
S2  Trust Intelligence
      ↓
S3  Risk Engine
      ↓
S4  AI Copilot
      ↓
S5  Enterprise Observability
      ↓
S6  Room-Specific Settlement Intelligence
      ↓
S7  Federated Trust Network
```

### S2 — Trust Intelligence

**Amaç:** Mevcut Trust Metrics v0.1 üzerine **davranış sinyali** katmanının mimari tanımı ve sinyal kataloğu. Oda ve actor bağlamında açıklanabilir gözlem üretimi. Engine'e yazma yok.

### S3 — Risk Engine

**Amaç:** Sinyal ve metrikleri birleştiren **istatistiksel risk gözlemi** katmanı. Karar veya engelleme yok; confidence ve basis zorunlu. Copilot ve Enterprise için girdi.

### S4 — AI Copilot

**Amaç:** Kullanıcıya sözleşme, müzakere ve geçmiş bağlamında **açıklama ve öneri** sunan LLM katmanı. §7 güvenlik prensipleri bağlayıcı. Engine mutasyonu yok.

### S5 — Enterprise Observability

**Amaç:** Organizasyon / portföy düzeyinde escrow sağlığı, dispute oranı, tamamlama süreleri, actor agregasyonları — B2B ve operasyon ekipleri için salt okunur görünürlük. Bireysel Actor Trust'ın kurumsal üst kümesi.

### S6 — Room-Specific Settlement Intelligence

**Amaç:** Settlement ve recovery kanıt zincirinin oda bazlı intelligence ile desteklenmesi (S1 employer guard sonrası uzun vadeli hedef). **Not:** Settlement mutasyonu yine Engine'de kalır; intelligence yalnızca kanıt okuma ve reconciliation *gözlemi* sağlar. INV-A8 treasury gap gibi muhasebe tutarlılığı **gözlemi** bu sprint vizyonuna girer; düzeltme Engine sprint'lerinde kalır.

### S7 — Federated Trust Network

**Amaç:** Platformlar arası **anonimleştirilmiş, opt-in** güven sinyali paylaşım vizyonu (ör. sektör ortalaması, dispute frekansı bandı). Zinesh içi actor verisi dışarı sızmaz; federasyon yalnızca aggregate ve politika onaylı paketler. Uzun vadeli; regülasyon ve gizlilik önce.

---

## Stable Core Dokunulmazlık Beyanı

Aşağıdaki sistemler Trust Intelligence roadmap'i kapsamında **değiştirilmez**:

- `escrow_room_lib.php` / `wallet_lib.php`
- State machine, settlement, dispute, recovery
- Escrow memory emit (event üretimi engine sorumluluğunda kalır)
- Timeline, AI Context, Trust Metrics, Actor Trust **mevcut davranışları** — yeni katmanlar **üzerine eklenir**, mevcut API'ler breaking change olmadan genişletilebilir (ayrı sprint kararı)

---

## Belge Onayı

| Rol | Ad | Tarih | Onay |
|-----|-----|-------|------|
| Engineering | | | |
| Product | | | |
| Legal / Compliance (S7 öncesi) | | | |

---

*Bu belge Zinesh'in ikinci nesil güven altyapısının teknik manifestosudur. Implementasyon detayı, API şeması ve depolama seçimleri bu belgenin kapsamı dışındadır ve ilgili sprintlerde ayrıca yazılacaktır.*
