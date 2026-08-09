# Trust Domain Model

> **Bu doküman implementasyon planı değildir.**
>
> Bu doküman, Zinesh Trust Intelligence ekosisteminin resmi kavramsal modelini tanımlar.
>
> Burada tanımlanan kavramlar, gelecekteki tüm implementasyonlar için ortak dil (Ubiquitous Language) olarak kullanılacaktır.
>
> Kod, API, Risk Engine, Observation Engine, AI Copilot, Enterprise Observability ve gelecekteki tüm katmanlar bu modele uymak zorundadır.

**Model versiyonu:** 1.0  
**Tarih:** 2026-08-07  
**Sprint:** S3.1A.2 — Trust Domain Model (Ubiquitous Language)  
**Bounded Context:** Trust Intelligence  
**Üst belgeler:** `TRUST_INTELLIGENCE_ARCHITECTURE.md`, `TRUST_ONTOLOGY.md`, `TRUST_SIGNAL_CATALOG.md`, `CONTEXT_TAXONOMY.md`, `RISK_OBSERVATION_MODEL.md`

---

## 0. Bounded Context

Bu Domain Model yalnızca **Trust Intelligence** bounded context'i için geçerlidir.

### Kapsam dışı (veri kaynağı olarak okunur; değiştirilemez)

| Sistem | Rol |
|--------|-----|
| Escrow Engine | Oda state machine; domain event emit kaynağı |
| Wallet Engine | Bakiye ve escrow mutasyonu |
| Settlement Engine | Ödeme tamamlama akışı |
| Dispute Engine | İtiraz süreci |
| Recovery Engine | TTL ve recovery aksiyonları |
| Payment Engine | Ödeme kanalı |
| Blockchain katmanı | On-chain işlemler (varsa) |

**Kural:** Trust Intelligence bu sistemleri **değiştiremez**. Yalnızca **okuyabilir** (`TRUST_INTELLIGENCE_ARCHITECTURE.md` §1, §9).

---

## 1. Amaç

### Trust Domain Model nedir?

**Trust Domain Model**, Zinesh Trust Intelligence ekosistemindeki tüm kavramların, katmanların, kuralların ve ilişkilerin **tek resmi tanımını** içeren kavramsal modeldir. Mühendislik, ürün, operasyon ve AI katmanları aynı terimleri aynı anlamda kullanır.

### Ne değildir?

| Değildir | Açıklama |
|----------|----------|
| Implementasyon planı | Kod, dosya, API veya deploy tanımı içermez |
| Escrow domain modeli | Para, state machine ve settlement kuralları burada tanımlanmaz |
| Hukuki çerçeve | Mahkeme veya hakem kararı değildir |
| AI prompt şablonu | Copilot içeriği değildir |

### Neden gereklidir?

1. **Çok belge, tek dil** — S2–S3 sprintlerinde üretilen mimari belgeler aynı kavramları bazen farklı isimlerle kullanmıştır (§13).
2. **Stable Core izolasyonu** — Trust katmanının escrow'dan ayrımı terminolojiyle de korunur.
3. **Regülasyon ve denetim** — "Gözlem", "risk", "confidence" kelimelerinin yanlış yorumlanması önlenir.
4. **Uzun ömür** — Yeni katmanlar (Enterprise, Federated) eklendiğinde mevcut zincir bozulmaz.

### Ortak dil neden önemlidir?

`TRUST_ONTOLOGY.md`: Trust bilgidir; karar değildir. Yanlış terim (ör. confidence = olasılık) ürün, hukuk ve mühendislikte aynı anda hata üretir. Ubiquitous Language, DDD'de bounded context içindeki terimlerin **çeviri gerektirmeden** paylaşılmasını sağlar.

---

## 2. Sistemin Kavramsal Zinciri

Resmi üretim zinciri (`RISK_OBSERVATION_MODEL.md` §2, §8; `CONTEXT_TAXONOMY.md` §2):

```
Event
    ↓
Metric
    ↓
Signal
    ↓
Context
    ↓
Risk Observation
    ↓
AI Explanation
    ↓
Human Decision
```

**Not — Observation kavramı:** Ontoloji düzeyinde **Observation (Gözlem)**, kanıta dayalı trust çıktısının **üst kavramıdır** (`TRUST_ONTOLOGY.md` §1). Runtime zincirinde ayrı katman değildir. **Trust Signal** atomik gözlem; **Risk Observation** birleşik gözlemdir (`RISK_OBSERVATION_MODEL.md` §1). Zincirdeki somut çıktılar Signal ve Risk Observation'dır.

---

### Event

| Alan | Tanım |
|------|-------|
| **Tanım** | Escrow bounded context'te gerçekleşen, append-only kayda alınmış **Domain Event**. Trust katmanının kanıt kaynağıdır. |
| **Girdi** | Escrow Engine state geçişleri (`escrow_memory_lib.php` emit) |
| **Çıktı** | Tipi, zaman damgası, oda kimliği, sanitize payload içeren kayıt |
| **Sorumluluk** | "Ne oldu?" sorusuna yanıt; değiştirilemez geçmiş |
| **Yapmaması gerekenler** | Metric hesaplamaz; signal üretmez; yorum içermez |

---

### Metric

| Alan | Tanım |
|------|-------|
| **Tanım** | Domain Event'lerden **deterministik** türetilen sayı, süre, oran veya frekans (`TRUST_INTELLIGENCE_ARCHITECTURE.md` §2). |
| **Girdi** | Domain Event listesi; oda snapshot (salt okuma) |
| **Çıktı** | `negotiation_rounds`, `counter_offer_count`, `successful_settlement` vb. |
| **Sorumluluk** | Ölçmek ve saymak; yorum cümlesi üretmemek |
| **Yapmaması gerekenler** | Signal cümlesi üretmez; context uygulamaz; karar vermez |

---

### Signal (Trust Signal)

| Alan | Tanım |
|------|-------|
| **Tanım** | Catalog tanımlı (`SIG-*`), metric ve event'e dayalı **atomik davranış gözlemi** (`TRUST_SIGNAL_CATALOG.md` §1). |
| **Girdi** | Metric; Domain Event; (gerekirse) Actor Trust metrikleri |
| **Çıktı** | `signal_id`, gözlem metni, evidence chain, confidence |
| **Sorumluluk** | Nötr, tek boyutlu gözlem; kanıt referansı |
| **Yapmaması gerekenler** | Context uygulamaz; risk observation üretmez; etiket/niyet/hüküm içermez |

---

### Context

| Alan | Tanım |
|------|-------|
| **Tanım** | Signal'ın **hangi çerçevede okunacağını** tanımlayan çok boyutlu metadata modeli (`CONTEXT_TAXONOMY.md` §1). |
| **Girdi** | Oda metadata, structured terms, actor profil alanları (mevcut kayıtlar) |
| **Çıktı** | Boyut vektörü (`job_type`, `complexity`, …), kategori listesi (`CTX-*`) |
| **Sorumluluk** | Yorumlama çerçevesi sağlamak; signal sayısını değiştirmemek |
| **Yapmaması gerekenler** | Event değiştirmez; signal metriğini düzeltmez; risk üretmez; karar vermez |

---

### Risk Observation

| Alan | Tanım |
|------|-------|
| **Tanım** | Signal'ların ve Context'in birleşimiyle üretilen, catalog tanımlı (`OBS-*`) **birleşik üst düzey gözlem** (`RISK_OBSERVATION_MODEL.md` §1). |
| **Girdi** | Signal[]; Context; (opsiyonel) Cohort |
| **Çıktı** | `observation_id`, `statement`, `confidence`, `basis`, `fired` |
| **Sorumluluk** | Desen gözlemi; explainability; replayability |
| **Yapmaması gerekenler** | Karar, ceza, skor, olasılık, etiket, işlem durdurma |

---

### AI Explanation

| Alan | Tanım |
|------|-------|
| **Tanım** | Risk Observation paketinin **doğal dilde açıklanması**; AI Copilot katmanının çıktı türü (`TRUST_INTELLIGENCE_ARCHITECTURE.md` §6). |
| **Girdi** | Sanitize AI Context; Trust Metrics; Trust Signal; Risk Observation paketi |
| **Çıktı** | Doğal dil özet ve bilgilendirme (non-deterministic) |
| **Sorumluluk** | Anlatmak ve bilgilendirmek |
| **Yapmaması gerekenler** | Signal/observation değiştirmez; risk üretmez; mutasyon tetiklemez; karar vermez |

---

### Human Decision

| Alan | Tanım |
|------|-------|
| **Tanım** | Kullanıcı veya yetkili operatörün **nihai kararı** — onay, red, ödeme, dispute, tamamlama (`RISK_OBSERVATION_MODEL.md` §2). |
| **Girdi** | UI; AI Explanation; Risk Observation; escrow kuralları |
| **Çıktı** | Engine mutasyonu (escrow bounded context'te) |
| **Sorumluluk** | Taahhüt ve karar |
| **Yapmaması gerekenler** | Trust katmanı tarafından otomatik üretilemez |

---

## 3. Resmi Kavram Sözlüğü

### Event

| | |
|-|-|
| **Tanım** | Trust zincirindeki en alt kanıt birimi; kayıtlı olay. |
| **Ne değildir** | Metric, signal, yorum, state machine iç durumu (emit edilmemişse) |
| **Kim üretir** | Escrow Engine (emit); Trust Intelligence **üretmez** |
| **Kim tüketir** | Metric katmanı; Timeline türetimi |
| **İlişki** | → Metric |

---

### Domain Event

| | |
|-|-|
| **Tanım** | `zinesh_domain_event_types()` ile tanımlı, append-only store'da saklanan resmi event kaydı (`TRUST_INTELLIGENCE_ARCHITECTURE.md` §2). |
| **Ne değildir** | Marketing/analytics event (`events_lib.php`); ham state transition |
| **Kim üretir** | Escrow memory emit (`escrow_memory_lib.php` → `zinesh_domain_event_emit`) |
| **Kim tüketir** | Metric, Timeline, AI Context, Evidence Chain |
| **İlişki** | Trust katmanının **tek** event türü; Event = Domain Event |

---

### Timeline

| | |
|-|-|
| **Tanım** | Domain Event'lerden türetilen, oda bazlı **kronolojik insan-okunur** olay dizisi (`TRUST_INTELLIGENCE_ARCHITECTURE.md` §2). |
| **Ne değildir** | Metric; signal; karar kaydı |
| **Kim üretir** | Escrow memory / timeline derleyici (read model) |
| **Kim tüketir** | AI Context; Explainability doğrulama; UI; AI Copilot |
| **İlişki** | ← Domain Event; Timeline metric üretmez |

---

### Metric

| | |
|-|-|
| **Tanım** | Deterministik sayısal/süresel ölçüm. |
| **Ne değildir** | Gözlem cümlesi; skor; karar |
| **Kim üretir** | Trust Metrics katmanı (`trust_intelligence_lib.php` v0.1) |
| **Kim tüketir** | Trust Signal emitter; Actor Trust (agregasyon) |
| **İlişki** | ← Event; → Signal |

---

### Signal (Trust Signal)

| | |
|-|-|
| **Tanım** | `SIG-*` catalog tanımlı atomik davranış gözlemi. |
| **Ne değildir** | Risk Observation; context; trust score |
| **Kim üretir** | Trust Intelligence katmanı (signal emitter — gelecek runtime) |
| **Kim tüketir** | Risk Engine; AI Copilot |
| **İlişki** | ← Metric ← Event; → Risk Observation (girdi) |

---

### Context

| | |
|-|-|
| **Tanım** | Çok boyutlu yorumlama çerçevesi (`CONTEXT_TAXONOMY.md`). |
| **Ne değildir** | Risk; karar; trust score; AI çıktısı; signal |
| **Kim üretir** | Context resolver (Trust Intelligence bounded context) |
| **Kim tüketir** | Risk Engine; AI Copilot; Enterprise Observability |
| **İlişki** | Signal'dan **sonra** gelir; Event'ten **doğrudan** türemez |

---

### Observation (Gözlem — üst kavram)

| | |
|-|-|
| **Tanım** | Trust Intelligence'da kanıta dayalı, açıklanabilir **her türlü çıktının** ontoloji düzeyindeki ortak adı (`TRUST_ONTOLOGY.md` §1). |
| **Ne değildir** | Karar; reputation; trust score |
| **Kim üretir** | — (soyut kavram; somut üreticiler Signal ve Risk Engine) |
| **Kim tüketir** | AI Copilot; Human Decision (bilgi olarak) |
| **İlişki** | Somut türler: **Trust Signal** (atomik), **Risk Observation** (birleşik) |

---

### Observation Cluster

| | |
|-|-|
| **Tanım** | Birden fazla `observation_id`'nin mantıksal paketi; skor veya rank üretmez (`RISK_OBSERVATION_MODEL.md` §5). |
| **Ne değildir** | Risk puanı; öncelik sırası; karar |
| **Kim üretir** | Risk Engine (statik üyelik kuralları) |
| **Kim tüketir** | AI Copilot; Enterprise Observability (portföy agregasyonu ayrı) |
| **İlişki** | ← Risk Observation[]; `cluster_confidence` = min(üye confidence) |

---

### Risk Observation

| | |
|-|-|
| **Tanım** | `OBS-*` ile tanımlı, signal + context (+ opsiyonel cohort) birleşiminden türeyen resmi birleşik gözlem. |
| **Ne değildir** | Signal; karar; olasılık; ceza; etiket |
| **Kim üretir** | **Risk Engine** |
| **Kim tüketir** | AI Copilot; Enterprise Observability; Human Decision (bilgi) |
| **İlişki** | ← Signal + Context; → AI Explanation |

---

### Explainability

| | |
|-|-|
| **Tanım** | Her trust çıktısının **neden** üretildiğinin event timeline'a kadar izlenebilir olması (`TRUST_ONTOLOGY.md` §2; `RISK_OBSERVATION_MODEL.md` §6). |
| **Ne değildir** | LLM yaratıcılığı; gerekçesiz özet |
| **Kim üretir** | Signal ve Risk Observation paketlerinin zorunlu alanı (`basis`, evidence chain) |
| **Kim tüketir** | Human Decision; denetim; AI Copilot (okur, üretmez) |
| **İlişki** | Evidence Chain'in kullanıcıya görünür yüzü |

---

### Evidence

| | |
|-|-|
| **Tanım** | Trust çıktısını destekleyen **kayıtlı kanıt** — domain event, metric snapshot, catalog referansı. |
| **Ne değildir** | Sezgi; platform dışı bilgi; LLM uydurması |
| **Kim üretir** | Escrow Engine (event); Trust Metrics (sayı) |
| **Kim tüketir** | Signal; Risk Observation; Explainability |
| **İlişki** | Evidence Before Intelligence ilkesinin temeli |

---

### Evidence Chain

| | |
|-|-|
| **Tanım** | Risk Observation → Signal → Metric → Event → raw store zinciri (`RISK_OBSERVATION_MODEL.md` §6). |
| **Ne değildir** | Özet skor; tek satırlık gerekçe |
| **Kim üretir** | Risk Engine ve Signal emitter (paket içinde) |
| **Kim tüketir** | Explainability; denetim; e2e replay testleri |
| **İlişki** | Zorunlu; atlama yasak |

---

### Confidence

| | |
|-|-|
| **Tanım** | **Evidence Completeness** — gözlemin dayandığı kanıt zincirinin **yeterlilik düzeyi** (§9). |
| **Ne değildir** | Probability; AI confidence; risk confidence; karar doğruluğu |
| **Kim üretir** | Signal emitter; Risk Engine |
| **Kim tüketir** | UI; AI Copilot; Human Decision (yorumlama) |
| **İlişki** | `low` \| `medium` \| `high`; birleşik pakette min kuralı (`TRUST_SIGNAL_CATALOG.md` §4) |

---

### Actor Trust

| | |
|-|-|
| **Tanım** | Kullanıcı bazlı, çoklu oda **agregasyon metrikleri** (`TRUST_INTELLIGENCE_ARCHITECTURE.md` §2). |
| **Ne değildir** | Etiket; reputation; karar |
| **Kim üretir** | Actor Trust katmanı (`actor_trust_lib.php` v0.1) |
| **Kim tüketir** | Signal emitter; Risk Engine (`OBS-HIS-*`) |
| **İlişki** | ← Metric (oda düzeyinden agregasyon); yazmaz |

---

### Trust Metrics

| | |
|-|-|
| **Tanım** | **Oda bazlı** deterministik metrik paketi — runtime katman adı (`TRUST_INTELLIGENCE_ARCHITECTURE.md` §2). |
| **Ne değildir** | Signal; Risk Observation; Actor Trust |
| **Kim üretir** | Trust Metrics katmanı (`trust_intelligence_lib.php`, `trust_metrics_lib.php`) |
| **Kim tüketir** | Trust Intelligence (signal); Risk Engine; AI Context |
| **İlişki** | Metric ⊂ Trust Metrics paketi |

---

### AI Context

| | |
|-|-|
| **Tanım** | Tek oda için **sanitize** edilmiş bağlam paketi — sözleşme versiyonları, timeline özeti, davranış istatistikleri (`TRUST_INTELLIGENCE_ARCHITECTURE.md` §2). |
| **Ne değildir** | Context taxonomy vektörünün tamamı değil (örtüşür); ham PII; recovery tetikleyici |
| **Kim üretir** | AI Context katmanı (`ai_context_lib.php`) |
| **Kim tüketir** | Trust Metrics; Context resolver; AI Copilot |
| **İlişki** | ← Timeline, Domain Event, room snapshot; PII sızdırmaz |

---

### Human Decision

| | |
|-|-|
| **Tanım** | Trust çıktılarını tüketen **insan veya operatör** nihai kararı. |
| **Ne değildir** | Sistem çıktısı; otomatik aksiyon |
| **Kim üretir** | İnsan (kullanıcı, operasyon, hakem sürecinde insan) |
| **Kim tüketir** | Escrow Engine (karar sonucu mutasyon) |
| **İlişki** | Trust zincirinin **son** tüketicisi; üreticisi değil |

---

### Terminoloji çakışması giderildi

| Eski / belirsiz kullanım | Resmi isim |
|--------------------------|------------|
| Observation Engine (domain) | **Risk Engine** (runtime katmanı); "Observation Engine" yalnızca implementasyon sprint adı |
| Observation (runtime katmanı) | **Risk Observation** (`OBS-*`) |
| Observation (ontoloji) | **Observation (Gözlem)** üst kavram |
| AI Yorumu / AI Explanation | **AI Explanation** (çıktı türü); katman adı **AI Copilot** |
| Enterprise Intelligence | **Enterprise Observability** |
| Escrow Event (kayıtlı) | **Domain Event** |
| Güvenilirlik / veri güvenilirliği (confidence) | **Evidence Completeness** |

---

## 4. Escrow Event ↔ Trust Domain Event

### Aynı şey midir?

**Kısmen.** Trust Intelligence bounded context'te **yalnızca Domain Event** vardır. "Escrow Event" escrow bounded context dilidir.

### Neden aynı değildir?

| Kavram | Bounded Context | Trust katmanında görünürlük |
|--------|-----------------|------------------------------|
| **Ham Escrow Event** | Escrow Engine | **Görünmez** — state machine iç geçişi; emit edilmemişse kanıt değildir |
| **Domain Event** | Trust Intelligence (okuma) | **Görünür** — append-only, tipi sabit, replay edilebilir |

`TRUST_INTELLIGENCE_ARCHITECTURE.md` §9: "Gerçek kaynak domain event'lerdir."

### Dönüşüm

Trust katmanında ayrı bir "dönüşüm motoru" yoktur. Escrow Engine, state geçişlerinde **doğrudan** Domain Event emit eder:

```
Escrow state geçişi (Escrow BC)
    ↓ emit (escrow_memory_lib)
Domain Event kaydı (append-only, tip + payload + metadata)
    ↓ read
Trust Intelligence (Metric → …)
```

**Ham geçiş → Domain Event** adımları (kavramsal):

1. State machine geçişi gerçekleşir (Escrow Engine).
2. `zinesh_domain_event_emit` çağrılır; tip whitelist'ten seçilir.
3. Payload sanitize edilir; idempotency metadata eklenir.
4. Kayıt append-only store'a yazılır.

Emit **edilmeyen** geçişler Trust Intelligence için **yok hükmündedir** (`TRUST_ONTOLOGY.md` §6: eksik veri ≠ kanıt).

---

## 5. Katmanlar Arası Kurallar

### İzinli bağımlılık (yalnızca aşağı → yukarı)

```
Event → Metric → Signal → Context → Risk Observation → AI Explanation → Human Decision
```

**Yan yol (agregasyon, aynı seviye okuma):**

- Actor Trust ← Metric (oda metriklerinden agregasyon)
- AI Context ← Domain Event, Timeline, room snapshot
- Timeline ← Domain Event
- Observation Cluster ← Risk Observation (paketleme)

### Yasak geçişler

| Yasak | Gerekçe |
|-------|---------|
| Metric ← Signal | Metric signal'dan türemez |
| Event ← Metric | Event türev değil, kaynak |
| Signal ← Event (doğrudan) | Signal yalnızca Metric üzerinden (`TRUST_SIGNAL_CATALOG.md`) |
| Context ← Event (doğrudan) | Context Before Risk; metric/signal atlanamaz |
| Risk Observation ← Event (doğrudan) | Evidence chain ihlali |
| Risk Observation ← Metric (doğrudan) | Signal atlanamaz |
| Risk Observation ← Context (doğrudan, signalsiz) | `RISK_OBSERVATION_MODEL.md` §3 |
| Signal ← Risk Observation | Ters akış yasak |
| AI Explanation ← Event (doğrudan) | Copilot ham event okumaz |
| AI Explanation ← Metric (doğrudan) | Risk/signal paketi atlanamaz |
| AI → Signal değiştirme | Non-deterministic katman deterministic çıktıyı değiştiremez |
| AI → Risk Observation değiştirme | Aynı |
| AI → Risk Observation üretme | Deterministik katman işi |
| Trust Intelligence → Escrow yazma | Stable Core / Read Only |
| Risk Engine → işlem durdurma | `RISK_OBSERVATION_MODEL.md` §7 |
| Human Decision ← sistem otomatik üretim | Human in Control |
| Enterprise / Federated → bireysel observation mutasyonu | Privacy First |

---

## 6. Domain Invariants

Trust Intelligence bounded context için **değiştirilemez** kurallar:

| # | İnvariant | Kaynak |
|---|-----------|--------|
| I1 | **Stable Core dokunulmazdır** — escrow, wallet, settlement, recovery yazılmaz | Architecture §1 |
| I2 | **Read Only** — intelligence stack yazmaz | Architecture §9 |
| I3 | **Event geçmişi değiştirilemez** — append-only; silme/yeniden yazma yok | Architecture §9 Event Sourcing |
| I4 | **Signal doğrudan Event'ten üretilemez** — Metric zorunlu | Signal Catalog; Ontology |
| I5 | **Context Risk değildir** — risk girdisi, risk çıktısı değil | Context Taxonomy §1 |
| I6 | **Context Signal'ı değiştirmez** | Context Taxonomy §2 |
| I7 | **Risk Observation karar değildir** | Risk Observation Model §1 |
| I8 | **Risk işlem durduramaz** | Risk Observation Model §7 |
| I9 | **AI karar veremez** | Architecture §6 |
| I10 | **Explainability zorunludur** — `fired` observation'da boş basis yasak | Risk Model §6 |
| I11 | **Evidence olmadan Risk Observation üretilemez** | Evidence Before Intelligence |
| I12 | **Human in Control** — otomatik aksiyon yok | Ontology §10 |
| I13 | **Deterministic core** — Event+Metric+Signal+Context+Risk Observation aynı girdi → aynı çıktı | Ontology §2 |
| I14 | **LLM deterministic katmanda yok** | Risk Model §6 |
| I15 | **Observation without signal yasak** | Risk Model §6 |
| I16 | **Trust bilgidir; para değildir** | Ontology §1 |
| I17 | **Etiket / niyet / kişilik analizi yasak** | Risk Model §7 |
| I18 | **Confidence = Evidence Completeness** — olasılık değil | §9 bu belge |
| I19 | **Privacy First** — PII sızdırma yok; actor self-access | Architecture §9 |
| I20 | **Kapatılabilirlik** — intelligence kapalıyken escrow çalışır | Architecture §1 |

---

## 7. Kavram İlişkileri

### Bağımlılık grafiği (yönlü, döngüsüz)

```
Domain Event
    ├── Timeline
    ├── Metric ──→ Trust Signal ──┐
    │         └──→ Actor Trust ───┼──→ (signal girdisi)
    ├── AI Context ──→ Context ───┘
    │                      │
    └──────────────────────┼──→ Risk Observation ──→ Observation Cluster
                           │           │
                           │           └──→ Evidence Chain / Explainability
                           │                       │
                           └───────────────────────┴──→ AI Explanation ──→ Human Decision
```

### Tersine bağımlılık yok

| Kavram | Kullanır | Kullanılmaz (üstten) |
|--------|----------|----------------------|
| Metric | Event | Signal, Risk Observation |
| Trust Signal | Metric, Event | Risk Observation (çıktı olarak değil, girdi) |
| Context | AI Context, room metadata | Event (doğrudan) |
| Risk Observation | Signal, Context | AI Explanation (girdi olarak) |
| AI Explanation | Risk Observation paketi | Human Decision (üretmez) |

### Dairesel bağımlılık analizi

**Döngü yoktur.** Tek risk: implementasyonda Risk Engine'in Actor Trust okurken `escrow_rooms_load` kullanması — bu **okuma**, feedback loop değildir; observation escrow state'i değiştirmez (I1, I2).

---

## 8. Terminoloji Birliği

Mevcut belgelerdeki isimler incelendi. **Resmi isim** aşağıdadır. Yeni isim üretilmedi.

| Belgede geçen | Resmi isim | Not |
|---------------|------------|-----|
| Observation Engine | **Risk Engine** | `OBSERVATION_ENGINE_IMPLEMENTATION_PLAN.md` implementasyon sprint adı; domain/runtime adı **Risk Engine** |
| Risk Engine | **Risk Engine** | Değişmez |
| AI Copilot | **AI Copilot** | Katman adı |
| AI Explanation / AI Yorumu | **AI Explanation** | Çıktı türü; katman **AI Copilot** |
| Observation (genel) | **Observation (Gözlem)** | Üst kavram (ontoloji) |
| Observation (OBS-*) | **Risk Observation** | Catalog çıktısı |
| Risk Observation | **Risk Observation** | Değişmez |
| Confidence | **Confidence** | Anlam: **Evidence Completeness** (§9) |
| Evidence | **Evidence** | Değişmez |
| Explainability | **Explainability** | Değişmez |
| Enterprise Intelligence | **Enterprise Observability** | `TRUST_ONTOLOGY.md` roadmap → Architecture adı esas |
| Trust Intelligence (katman) | **Trust Intelligence** | Signal üretim katmanı |
| trust_intelligence_lib (bugün) | **Trust Metrics** (mevcut runtime) | Mimari boşluk: lib adı ≠ tam katman (`Architecture` §2) |

---

## 9. Confidence Tanımı

### Resmi anlam (değiştirilemez)

**Confidence = Evidence Completeness**

> Confidence, bir Trust Signal veya Risk Observation ifadesinin dayandığı **Event → Metric → Signal → Context** kanıt zincirinin **ne ölçüde eksiksiz ve yeterli** olduğunu gösteren ordinal göstergedir (`low` | `medium` | `high`).

### Reddedilen anlamlar

| Anlam | Neden reddedildi |
|-------|------------------|
| **AI Confidence** | LLM katmanı deterministic core'u değiştirmez; confidence deterministic katmanda atanır |
| **Probability** | `RISK_OBSERVATION_MODEL.md` §1: olasılık tahmini değil |
| **Risk Confidence** | Risk olasılık veya risk skoru değildir; yanlış çağrışım |
| **Evidence Strength** | "Güç" normatif yük taşır; completeness nötr ve ölçülebilir (n, unknown count, event span) |

### Ölçüm girdileri (referans uyumlu)

- Event zinciri uzunluğu ve süre span'i
- Sample size (n) — actor transaction count
- Unknown context boyut sayısı
- `context_required` signal'larda context çözüm kalitesi
- Birleşik pakette: **min**(üye confidence) (`TRUST_SIGNAL_CATALOG.md` §4)

---

## 10. Scope Sınırları

### Observation (üst kavram) ve Risk Observation

| Yapmaz | Açıklama |
|--------|----------|
| Karar vermez | Onay/red/ödeme yok |
| Skor üretmez | Tek sayılı trust score yok |
| Prediction yapmaz | Gelecek tahmini iddiası yok |

### Risk Observation (somut)

| Yapmaz | Açıklama |
|--------|----------|
| Ceza vermez | `RISK_OBSERVATION_MODEL.md` §7 #6 |
| Kullanıcıyı etiketlemez | #3, #4 |
| İşlemi durduramaz | #7 |

### AI (AI Copilot)

| Yapmaz | Açıklama |
|--------|----------|
| Signal değiştirmez | Determinism |
| Risk Observation değiştirmez | Aynı |
| Risk üretmez | Risk Engine sorumluluğu |

### Human

| Yapar | Açıklama |
|-------|----------|
| Son kararı verir | Human in Control; tek nihai karar otoritesi |

---

## 11. Evolution Model

### Katman evrim sırası (resmi roadmap)

```
Trust Ontology
    ↓
Trust Signal Catalog
    ↓
Context Taxonomy
    ↓
Risk Observation Model
    ↓
Trust Metrics (mevcut) + Trust Intelligence (signal)
    ↓
Risk Engine
    ↓
AI Copilot
    ↓
Enterprise Observability
    ↓
Federated Trust Network
```

### Yeni katman kuralları

Her yeni katman:

1. **§6 Domain Invariants**'a uyar
2. **Evidence Before Intelligence** zincirini atlamaz
3. **Stable Core'a yazmaz**
4. **Human Decision'ı otomatikleştirmez**
5. **Ubiquitous Language**'e §12 süreciyle yeni terim ekler
6. Bir üst katmanın çıktısını **okur**; alt katmanın çıktısını **değiştirmez**
7. Kendi **version** alanını taşır

### Risk Engine → sonraki katmanlar

| Katman | Risk Engine'den alır | Ek sorumluluk |
|--------|----------------------|---------------|
| AI Copilot | Sanitize Risk Observation paketi | AI Explanation |
| Enterprise Observability | Cluster frekansı, sektör kırılımı | Portföy agregasyonu (bireysel PII yok) |
| Federated Trust Network | Yalnızca aggregate segment | Opt-in; bireysel observation dışarı çıkmaz |

---

## 12. Living Domain Model

### Yeni kavram ekleme kuralları

1. **Ontology uyumu** — `TRUST_ONTOLOGY.md` ile çelişemez
2. **Tek isim** — §8'e ekleme; eş anlamlı ikinci isim yasak
3. **Katman ataması** — §2 zincirinde yeri tanımlanır
4. **Yasak listesi** — §10 scope kontrolü
5. **Invariant kontrolü** — §6'ya aykırı invariant eklenemez
6. **Amend süreci** — Ontology → Catalog → Context → Risk Model sırası (`RISK_OBSERVATION_MODEL.md` §10)

### Terminoloji koruma

- Yeni belgeler `TRUST_DOMAIN_MODEL.md`'ye referans verir
- Çakışma durumunda: **Trust Domain Model > implementasyon planı > sprint notu**
- "Observation Engine" yalnızca tarihsel sprint adı olarak kalır; domain dili **Risk Engine** kullanır

### Versioning

| Alan | Kural |
|------|-------|
| `trust_domain_model_version` | Semver: 1.0, 1.1, 2.0 |
| Minor | Yeni kavram, netleştirme, drift giderimi |
| Major | İnvariant değişikliği, zincir değişikliği (nadir; ontology onayı) |
| Alt belge versiyonları | `model_version`, `catalog_version`, `taxonomy_version` ayrı; domain model bunlara referans verir |

---

## 13. Architecture Review

### Terminoloji çakışmaları

| # | Çakışma | Belgeler | Çözüm (bu sprint) |
|---|---------|----------|-------------------|
| T1 | Observation Engine vs Risk Engine | Implementation Plan vs Architecture, Risk Model | Resmi: **Risk Engine** |
| T2 | Observation vs Risk Observation | Ontology vs Risk Model vs Implementation Plan | Üst kavram vs **Risk Observation** (`OBS-*`) |
| T3 | Enterprise Intelligence vs Enterprise Observability | Ontology §11 vs Architecture §3 | Resmi: **Enterprise Observability** |
| T4 | AI Yorumu vs AI Explanation vs AI Copilot | Architecture §6, §9 | Katman: **AI Copilot**; çıktı: **AI Explanation** |
| T5 | Trust Intelligence (katman) vs trust_intelligence_lib (metrik) | Architecture §2 boşluk | Katman = signal; mevcut lib = **Trust Metrics** |
| T6 | Confidence: "gözlem güvenilirliği" vs "veri güvenilirliği" | Implementation Plan vs Signal Catalog | Birleşik: **Evidence Completeness** |
| T7 | Event vs Escrow Event vs Domain Event | Çeşitli | Trust BC: yalnızca **Domain Event** |
| T8 | Context vs AI Context | Architecture vs Taxonomy | **AI Context** = paket; **Context** = taxonomy vektörü |

### Aynı kavramın farklı isimleri

Yukarıdaki T1–T8 tablosu.

### Eksik tanımlar (referans belgelerde — bu sprintte tamamlandı)

- Observation (üst kavram) vs Risk Observation ayrımı
- Confidence resmi anlamı
- Escrow Event vs Domain Event sınırı
- Observation Engine / Risk Engine ilişkisi
- Evidence vs Evidence Chain ayrımı

### Tekrarlanan tanımlar

- "Evidence Before Intelligence" — Architecture, Ontology, Context Taxonomy, Risk Model (tutarlı tekrar; domain modelde §6'da tek invariant seti)
- Signal tanımı — Ontology §3, Signal Catalog §1 (uyumlu)
- Context "ne değildir" — Context Taxonomy §1, Risk Model §1 (uyumlu)

### Eksik domain kuralları (referanslarda dağınık — bu sprintte §5–§6'da birleştirildi)

- Tüm yasak geçişler tek tabloda
- Human Decision sistem üretimi yasağı
- Cluster'ın Risk Engine sorumluluğu

---

### Terminology Drift Analysis

| Kavram | Belge A | Belge B | Resmi isim |
|--------|---------|---------|------------|
| Runtime observation katmanı | Observation Engine (Implementation Plan) | Risk Engine (Architecture, Risk Model) | **Risk Engine** |
| Birleşik gözlem çıktısı | Observation (Implementation Plan başlık) | Risk Observation (Risk Model) | **Risk Observation** |
| Üst gözlem kavramı | observations (Ontology) | — | **Observation (Gözlem)** |
| Enterprise katmanı | Enterprise Intelligence (Ontology §11) | Enterprise Observability (Architecture) | **Enterprise Observability** |
| AI katmanı | AI Yorumu (Architecture §9) | AI Copilot (Architecture §6) | **AI Copilot** / çıktı **AI Explanation** |
| Confidence anlamı | gözlem güvenilirliği (Implementation Plan) | veri güvenilirliği (Signal Catalog) | **Evidence Completeness** |
| Signal üretim katmanı | Trust Intelligence (Architecture §4) | trust_intelligence_lib = metrics (Architecture §2) | Katman: **Trust Intelligence**; bugünkü lib: **Trust Metrics** |
| Zincirde Context | Risk Model §8 (var) | Architecture §9 Evidence chain (Context atlanmış) | **Context zorunlu** — Risk Model ve Context Taxonomy esas |

---

## 14. Resmi Domain Tablosu

| Kavram | Resmi Tanım | Resmi İsim | İlk Tanımlandığı Doküman | Bağımlı Olduğu Kavramlar | Bu Sprintte Değişti mi? |
|--------|-------------|------------|---------------------------|--------------------------|-------------------------|
| Event | Kayıtlı olay; trust kanıt kaynağı | **Event** | TRUST_INTELLIGENCE_ARCHITECTURE.md | — | Hayır (Domain Event ile birleştirildi) |
| Domain Event | Append-only, tipli escrow olay kaydı | **Domain Event** | TRUST_INTELLIGENCE_ARCHITECTURE.md | Escrow emit | Hayır |
| Timeline | Domain Event'lerden türetilen kronolojik dizi | **Timeline** | TRUST_INTELLIGENCE_ARCHITECTURE.md | Domain Event | Hayır |
| Metric | Deterministik sayım/süre/oran | **Metric** | TRUST_ONTOLOGY.md | Domain Event | Hayır |
| Trust Signal | SIG-* catalog atomik gözlem | **Trust Signal** | TRUST_SIGNAL_CATALOG.md | Metric, Domain Event | Hayır |
| Context | Çok boyutlu yorumlama çerçevesi | **Context** | CONTEXT_TAXONOMY.md | AI Context, room metadata | Hayır |
| Observation | Kanıta dayalı trust çıktısı üst kavramı | **Observation (Gözlem)** | TRUST_ONTOLOGY.md | Evidence | **Evet** — üst kavram netleştirildi |
| Risk Observation | OBS-* birleşik catalog gözlemi | **Risk Observation** | RISK_OBSERVATION_MODEL.md | Trust Signal, Context | Hayır |
| Observation Cluster | Risk Observation mantıksal paketi | **Observation Cluster** | RISK_OBSERVATION_MODEL.md | Risk Observation | Hayır |
| Explainability | Nedensel izlenebilirlik | **Explainability** | TRUST_ONTOLOGY.md | Evidence Chain | Hayır |
| Evidence | Kayıtlı kanıt birimi | **Evidence** | TRUST_ONTOLOGY.md | Domain Event, Metric | Hayır |
| Evidence Chain | Observation'dan event store'a zincir | **Evidence Chain** | RISK_OBSERVATION_MODEL.md | Evidence, Trust Signal, Metric, Domain Event | Hayır |
| Confidence | Kanıt zinciri yeterlilik düzeyi | **Confidence** (= Evidence Completeness) | TRUST_SIGNAL_CATALOG.md | Evidence Chain | **Evet** — resmi anlam sabitlendi |
| Actor Trust | Kullanıcı çoklu oda agregasyonu | **Actor Trust** | TRUST_INTELLIGENCE_ARCHITECTURE.md | Metric | Hayır |
| Trust Metrics | Oda bazlı metrik paketi/katmanı | **Trust Metrics** | TRUST_INTELLIGENCE_ARCHITECTURE.md | Domain Event, Metric | Hayır |
| AI Context | Sanitize oda bağlam paketi | **AI Context** | TRUST_INTELLIGENCE_ARCHITECTURE.md | Domain Event, Timeline | Hayır |
| Trust Intelligence | Signal üretim mimari katmanı | **Trust Intelligence** | TRUST_INTELLIGENCE_ARCHITECTURE.md | Trust Metrics, Actor Trust | Hayır |
| Risk Engine | Risk Observation üreten runtime katman | **Risk Engine** | TRUST_INTELLIGENCE_ARCHITECTURE.md | Trust Signal, Context, Risk Observation Model | **Evet** — Observation Engine alias giderildi |
| AI Copilot | Doğal dil açıklama katmanı | **AI Copilot** | TRUST_INTELLIGENCE_ARCHITECTURE.md | AI Explanation girdisi | Hayır |
| AI Explanation | Copilot çıktı türü | **AI Explanation** | RISK_OBSERVATION_MODEL.md | Risk Observation | Hayır |
| Human Decision | Nihai insan kararı | **Human Decision** | RISK_OBSERVATION_MODEL.md | AI Explanation, Risk Observation | Hayır |
| Enterprise Observability | Portföy/organizasyon gözlemi | **Enterprise Observability** | TRUST_INTELLIGENCE_ARCHITECTURE.md | Risk Observation Cluster | **Evet** — Enterprise Intelligence birleştirildi |
| Federated Trust Network | Opt-in aggregate trust | **Federated Trust Network** | TRUST_INTELLIGENCE_ARCHITECTURE.md | Enterprise Observability | Hayır |
| Stable Core | Escrow+wallet dokunulmaz çekirdek | **Stable Core** | TRUST_INTELLIGENCE_ARCHITECTURE.md | — (kapsam dışı) | Hayır |
| Evidence Before Intelligence | Katman atlama yasağı | **Evidence Before Intelligence** | TRUST_ONTOLOGY.md | Tüm zincir | Hayır |
| Human in Control | Otomatik karar yasağı | **Human in Control** | TRUST_ONTOLOGY.md | Human Decision | Hayır |

---

## SON KARAR

# **READY WITH MINOR TERMINOLOGY CHANGES**

Bu karar, **Trust Domain Model belgesinin kendisi** için geçerlidir: belge oluşturuldu ve terminoloji drift'i giderildi. Ekosistem genelinde uygulama için `OBSERVATION_ENGINE_IMPLEMENTATION_PLAN.md` ve gelecek sprint belgelerinin §8 terminoloji birliğine güncellenmesi önerilir (ayrı amend sprint).

---

## Özet

### Güçlü yönler
- Referans belgelerin çoğu aynı zinciri ve yasakları paylaşıyor
- Risk Observation ≠ karar ayrımı tutarlı
- Stable Core / Read Only ilkesi tüm belgelerde mevcut
- Evidence chain ve replay vizyonu net

### Zayıf yönler
- Observation Engine / Risk Engine isim drift'i
- Observation vs Risk Observation belirsizliği
- Architecture §9 zincirinde Context'in atlanması
- Trust Intelligence katman adı vs mevcut metrics-only lib

### Değişmesi gerekenler (terminoloji — bu belgede yapıldı)
- Risk Engine resmi runtime adı
- Confidence = Evidence Completeness
- Enterprise Observability resmi adı
- Observation üst kavram / Risk Observation somut çıktı ayrımı

### Değişmemesi gerekenler
- Evidence Before Intelligence sırası
- Domain Invariants (§6)
- Human in Control
- Stable Core izolasyonu
- Signal ≠ Risk Observation ayrımı
- Context ≠ Risk ayrımı

### Implementasyona hazır mı?
**READY WITH MINOR TERMINOLOGY CHANGES** — Domain model hazır; downstream belgeler (Implementation Plan amend) terminoloji hizası için güncellenmeli.

---

*Bu belge Zinesh Trust Intelligence ekosisteminin resmi Ubiquitous Language ve Domain Model dokümanıdır.*
