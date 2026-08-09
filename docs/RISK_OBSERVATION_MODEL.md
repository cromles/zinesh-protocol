# Risk Observation Model v1

> **Bu doküman bir implementasyon planı değildir.**
>
> Bu doküman, Zinesh Risk Observation katmanının mühendislik modelini tanımlar.
>
> Risk, karar değildir. Risk, olasılık tahmini değildir. Risk, kişilik analizi değildir. Risk, ceza değildir. Risk, işlemi durdurmaz.
>
> Risk, yalnızca mevcut kanıtların bağlam içerisinde hangi gözlemleri desteklediğini açıklar.
>
> Son karar her zaman kullanıcıya aittir.

**Model versiyonu:** 1.0  
**Tarih:** 2026-08-07  
**Sprint:** S3 — Risk Observation Model (mimari / dokümantasyon only)  
**Üst belgeler:** `TRUST_INTELLIGENCE_ARCHITECTURE.md`, `TRUST_ONTOLOGY.md`, `TRUST_SIGNAL_CATALOG.md`, `CONTEXT_TAXONOMY.md`

---

## 1. Amaç

### Risk Observation nedir?

**Risk Observation**, Trust Signal'ların ve Context'in bir araya gelmesiyle üretilen, **istatistiksel ve kanıta dayalı** üst düzey gözlemdir. Bir observation, "bu iş / bu actor bağlamında şu kanıt desenleri birlikte ne **söylüyor**" sorusuna yanıt verir — geleceği tahmin etmeden, karar vermeden, ceza uygulamadan.

Form:

> **Risk Observation** = f(Signals[], Context, optional Cohort) → Explainable Observation + Confidence

Örnek (nötr dil):

> "Yüksek revizyon sinyali, ikinci el ürün bağlamında ve sınırlı işlem geçmişi ile birlikte okunduğunda, sözleşme netliğinin kontrol edilmesi faydalı olabilir."

Bu bir **öneri değil**, **gözlem paketidir**; Copilot (S4) bunu cümleleştirir.

### Risk Observation ne değildir?

| Değildir | Açıklama |
|----------|----------|
| **Karar** | Ödeme, dispute, iptal, engel yok |
| **Olasılık tahmini** | "%%73 dolandırıcılık" yok |
| **Kişilik analizi** | Karakter, niyet, psikoloji yok |
| **Ceza** | Skor düşürme, hesap kısıtı yok |
| **Trust Score** | Tek sayılı itibar puanı değil |
| **Signal** | Signal atomik gözlem; observation birleşik çerçeve |
| **AI Explanation** | LLM çıktısı değil; deterministik katman |
| **Finansal / hukuki / ticari tavsiye** | Yatırım, sözleşme hukuku, iş stratejisi yok |

### Signal ile farkı

| Signal | Risk Observation |
|--------|------------------|
| Tek boyutlu, catalog tanımlı | Çok sinyilli, desen odaklı |
| "4 counter offer var" | "Müzakere karmaşıklığı yüksek **ve** bağlam X'te bu olağandışı **olabilir**" |
| Metric'ten doğrudan türetilir | Signal + Context + (opsiyonel) kohort kıyası |
| Her zaman üretilebilir (event varsa) | Confidence ve context'e bağlı |

### Metric ile farkı

Metric **sayıdır** (`negotiation_rounds: 6`). Risk Observation **sayıları bağlam içinde yorumlayan çerçevedir** — metric'i değiştirmez, metric'e referans verir.

### Context ile farkı

Context **yorumlama çerçevesidir** (boyutlar, kategoriler). Risk Observation **signal'ları bu çerçevede birleştiren çıktıdır**. Context observation değildir; observation'ın zorunlu girdisidir (`Context Before Risk`).

### AI Explanation ile farkı

| Risk Observation | AI Explanation |
|------------------|----------------|
| Deterministik | Non-deterministic (LLM) |
| `observation_id`, evidence chain | Doğal dil özeti |
| Risk Engine katmanı (S3) | Copilot katmanı (S4) |
| AI observation **üretmez**, **açıklar** | Observation paketini okur |

### Neden Risk Engine, Trust Intelligence'tan ayrı?

1. **Sorumluluk ayrımı** — Intelligence signal üretir; Risk signal'ları **birleştirir ve kohortla kıyaslar**.
2. **Determinism sınırı** — Signal katmanı event→metric→signal'da kalır; risk desenleri ayrı test edilir.
3. **Kapatılabilirlik** — Risk katmanı kapalıyken signal'lar ve escrow çalışır.
4. **Regülasyon ve etik** — "Risk" adı altında karar motoru ile gözlem motoru karışmamalı.
5. **Stable Core** — Escrow Engine risk çıktısına **bağlanmaz**.

---

## 2. Risk Yaşam Döngüsü

```
Event              → Domain kaydı (engine emit)
    ↓
Metric             → Deterministik sayım/süre/oran
    ↓
Signal             → Catalog tanımlı atomik gözlem
    ↓
Context            → Boyut vektörü + yorum çerçevesi
    ↓
Risk Observation   → Birleşik, kanıtlı üst gözlem
    ↓
AI Explanation     → İnsan dilinde açıklama (LLM)
    ↓
Human Decision     → Kullanıcı / operasyonel karar
```

### Katman sorumlulukları (yalnızca kendi işi)

| Katman | Yapar | Yapmaz |
|--------|-------|--------|
| **Event** | Olayı kaydeder | Metric hesaplamaz |
| **Metric** | Sayar, ölçer | Signal cümlesi üretmez |
| **Signal** | Nötr gözlem cümlesi | Context uygulamaz |
| **Context** | Çerçeve metadata | Signal değiştirmez |
| **Risk Observation** | Desen + kıyas gözlemi | Karar, ceza, engel |
| **AI Explanation** | Anlatır, önerir (bilgi) | Observation değiştirmez, mutasyon tetiklemez |
| **Human Decision** | Onaylar, reddeder, öder | — |

**Kural:** Hiçbir katman kendinden sonraki katmanın görevini üstlenemez. Özellikle Signal → Karar veya AI → Risk Observation atlama **yasaktır**.

---

## 3. Risk Observation İlkeleri

### Explainable

Her observation `observation_id`, `basis` (signals, metrics, events, context), `confidence` ve `taxonomy_version` taşır. Kullanıcı geriye doğru timeline'a inebilir.

### Deterministic

Aynı signal seti + aynı context vektörü + aynı model versiyonu + aynı kohort tanımı → aynı observation seti. LLM bu katmanda yoktur.

### Read Only

Risk Observation üretimi escrow, wallet, treasury, trust storage, kullanıcı erişimine **yazmaz**.

### Evidence Based

Observation yalnızca Event → Metric → Signal zincirine dayanır. Ham sezgi, dış veri (Federated hariç ve opt-in), LLM-invented kanıt yok.

### Context Aware

`context_required` signal içeren observation'lar context vektörü olmadan **üretilmez** veya confidence `low` + `insufficient_context` bayrağı ile üretilir.

### Replayable

Event log replay + catalog/taxonomy/model versiyonu sabit → observation seti yeniden üretilebilir.

### Composable

Observation'lar **Observation Cluster** (§5) ile paketlenir; cluster karar üretmez.

### Human Controlled

Observation UI'da bilgi; aksiyon kullanıcıda. Engine observation'a bağlı otomatik adım atmaz.

### No Black Box

Gizli ağırlık, gizli kohort, gizli eşik yok — model belgesinde veya versiyonlu config referansında (gelecek sprint) açık.

### No Automatic Decision

Observation hiçbir engine mutasyonunu, API reddini veya UI kilidini tetiklemez.

### No Punishment

Observation skor düşürmez, hesap kapatmaz, blacklist'e eklemez.

### No Hidden State

Risk katmanına giren tüm girdiler ve üretilen observation'lar denetlenebilir; "gizli risk notu" yok.

### Zaman sınırı

Risk **geleceği tahmin etmez**. Yalnızca geçmiş ve mevcut kanıtı, mevcut bağlamda yorumlar.

---

## 4. Observation Türleri

### Şablon alanları (tüm observation'lar)

- **Observation Name** / **ID**
- Amaç, Tanım
- Kaynak Eventler, Metricler, Signal'lar, Context
- Explainable / Replayable
- Tek başına karar üretir mi: **Hayır** (her zaman)
- False Positive / False Negative örnekleri

---

### OBS-REV-001 — High Revision Pattern

| Alan | Değer |
|------|-------|
| **ID** | OBS-REV-001 |
| **Amaç** | Sözleşme/şart revizyon yoğunluğunun bağlama göre dikkat gerektiren desen |
| **Tanım** | `SIG-NEG-001` + `SIG-CTR-001` birlikte eşik üstü; context'e göre "yüksek revizyon deseni" observation'ı |
| **Kaynak Eventler** | `terms_updated`, `counter_offer_created`, `terms_proposed` |
| **Metricler** | `negotiation_rounds`, version count, amount delta count |
| **Signal'lar** | SIG-NEG-001, SIG-CTR-001 |
| **Context** | `CTX-SEC` → dikkat çerçevesi; `CTX-SW` + `CTX-LNG` → nötr/ beklenen çerçeve |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | Yazılım projesinde normal scope refinement |
| **False Negative** | Revizyon mesajda, event emit edilmeden |

---

### OBS-DEL-001 — Delivery Uncertainty

| Alan | Değer |
|------|-------|
| **ID** | OBS-DEL-001 |
| **Amaç** | Teslim zamanlaması belirsizliği gözlemi |
| **Tanım** | `SIG-TIME-003` veya `SIG-TIME-004` + structured terms'de teslim alanı eksik/zayıf |
| **Kaynak Eventler** | `escrow_locked`, `settlement_completed`; contract structured terms |
| **Metricler** | `duration_seconds`, deadline presence |
| **Signal'lar** | SIG-TIME-003, SIG-TIME-004, SIG-CTR (eksik madde — yapısal) |
| **Context** | `CTX-DIG` → gecikme daha anlamlı; `CTX-CON` → tolerans çerçevesi geniş |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | Sözleşmede esnek teslim, kötü niyet sanılması |
| **False Negative** | Teslim tarihi serbest metinde, structured'da yok |

---

### OBS-CTR-001 — Contract Ambiguity

| Alan | Değer |
|------|-------|
| **ID** | OBS-CTR-001 |
| **Amaç** | Sözleşme yapısal belirsizliği (teslim, garanti, kapsam) |
| **Tanım** | Zorunlu structured alanların boş/eksik olması + aktif müzakere sinyalleri |
| **Kaynak Eventler** | `terms_proposed`, `terms_accepted`; contract versions |
| **Metricler** | structured field completeness score (kavramsal) |
| **Signal'lar** | SIG-NEG-003 (düşük müzakere + eksik alan çelişkisi) |
| **Context** | `contract_type`, `deliverable` boyutları |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | Basit iş için minimal sözleşme yeterli |
| **False Negative** | Belirsizlik metin içinde, yapısal skor düşük |

---

### OBS-TIM-001 — Frequent Deadline Changes

| Alan | Değer |
|------|-------|
| **ID** | OBS-TIM-001 |
| **Amaç** | Teslim/commitment tarihinin versiyonlar arası sık değişmesi |
| **Tanım** | Ardışık contract version'larda deadline alanı değişim sayısı ≥ eşik |
| **Kaynak Eventler** | `terms_updated`, contract versions |
| **Metricler** | deadline change count |
| **Signal'lar** | SIG-CTR-001 (volatility), SIG-TIME-004 |
| **Context** | `CTX-LNG`, `CTX-B2B` |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | Proje milestone kayması, mutabık uzatma |
| **False Negative** | Deadline sözlü değişti, kayıt yok |

---

### OBS-SET-001 — Settlement Instability

| Alan | Değer |
|------|-------|
| **ID** | OBS-SET-001 |
| **Amaç** | Ödeme tamamlama sürecinde istikrarsızlık deseni |
| **Tanım** | `SIG-SET-003` + `SIG-ACT-002` birlikte; veya düşük `SIG-SET-002` + failed settlement |
| **Kaynak Eventler** | `settlement_failed`, `escrow_recovered`, `settlement_completed` |
| **Metricler** | completion ratio, recovery count |
| **Signal'lar** | SIG-SET-002, SIG-SET-003, SIG-ACT-002 |
| **Context** | Engine vs actor ayrımı zorunlu (`CONTEXT_TAXONOMY` §5) |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | Actor güvensiz — oysa infra recovery |
| **False Negative** | Fail event emit edilmeden stuck |

---

### OBS-NEG-001 — High Negotiation Complexity

| Alan | Değer |
|------|-------|
| **ID** | OBS-NEG-001 |
| **Amaç** | Müzakere turu ve karşı teklif yoğunluğu üst dilim |
| **Tanım** | `SIG-NEG-002` + `SIG-TIME-002` + yüksek `negotiation_rounds` |
| **Kaynak Eventler** | `counter_offer_created`, `terms_rejected`, `changes_requested` |
| **Metricler** | `counter_offer_count`, `negotiation_rounds` |
| **Signal'lar** | SIG-NEG-002, SIG-TIME-002, SIG-NEG-001 |
| **Context** | `CTX-B2B` → beklenen olabilir; `CTX-ONE` → olağandışı olabilir |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | Karmaşık B2B sözleşmesi |
| **False Negative** | Platform dışı müzakere |

---

### OBS-HIS-001 — Low Historical Evidence

| Alan | Değer |
|------|-------|
| **ID** | OBS-HIS-001 |
| **Amaç** | Actor veya oda için yetersiz geçmiş veri |
| **Tanım** | `transactions` < eşik veya event zinciri kısa; confidence düşük |
| **Kaynak Eventler** | tüm domain events (seyrek) |
| **Metricler** | actor `transactions`, event count, time span |
| **Signal'lar** | SIG-BEH-001, SIG-ACT-001 (opsiyonel) |
| **Context** | `actor_history: first_transaction | novice` |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | "Riskli kullanıcı" hükmü |
| **False Negative** | Çok işlem ama hepsi yeni hesapta |

---

### OBS-HIS-002 — First-Time Transaction

| Alan | Değer |
|------|-------|
| **ID** | OBS-HIS-002 |
| **Amaç** | Actor'ün platformdaki ilk anlamlı işlem gözlemi |
| **Tanım** | `SIG-BEH-001` doğrulandığında observation olarak paketlenir |
| **Kaynak Eventler** | `settlement_completed`, `escrow_locked` |
| **Metricler** | actor transaction count = 0 veya 1 |
| **Signal'lar** | SIG-BEH-001 |
| **Context** | `amount_band` — yüksek tutarda bilgi notu |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | Otomatik red önerisi (yasak) |
| **False Negative** | Önceki hesap kapatılmış |

---

### OBS-HIS-003 — Limited Collaboration History

| Alan | Değer |
|------|-------|
| **ID** | OBS-HIS-003 |
| **Amaç** | Taraflar arası tekrar eden işbirliği yokluğu |
| **Tanım** | `party_relationship: marketplace_stranger` ve `SIG-BEH-002` yok |
| **Kaynak Eventler** | oda agregasyonu |
| **Metricler** | peer pair room count = 0 |
| **Signal'lar** | SIG-BEH-002 (negatif — signal yoksa observation) |
| **Context** | `CTX-SEC`, `frequency: one_off` |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | "Güvensiz yabancı" etiketi |
| **False Negative** | Aynı kişiler farklı hesap |

---

### OBS-CTX-001 — High Context Uncertainty

| Alan | Değer |
|------|-------|
| **ID** | OBS-CTX-001 |
| **Amaç** | Context boyutlarının `unknown` oranı yüksek |
| **Tanım** | Boyut vektöründe ≥N boyut `unknown` → yorum güvenilirliği düşük |
| **Kaynak Eventler** | — (metadata) |
| **Metricler** | unknown dimension count |
| **Signal'lar** | herhangi `context_required` signal |
| **Context** | Taxonomy tam vektör |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | Alarm yükseltme — oysa veri eksik |
| **False Negative** | Yanlış seçilmiş iş türü (kullanıcı hatası) |

---

### OBS-SCP-001 — Repeated Scope Change

| Alan | Değer |
|------|-------|
| **ID** | OBS-SCP-001 |
| **Amaç** | Kabul sonrası veya kilit öncesi kapsam değişimi deseni |
| **Tanım** | `SIG-CTR-001` + post-acceptance `terms_updated` (SIG-CTR-002 ihlali) |
| **Kaynak Eventler** | `terms_accepted`, `terms_updated`, `counter_offer_created` |
| **Metricler** | post-acceptance change count, amount delta |
| **Signal'lar** | SIG-CTR-001, SIG-CTR-002 (negatif) |
| **Context** | `CTX-SW` vs `CTX-SEC` |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | Agreed change order profesyonel projede |
| **False Negative** | Scope değişimi sohbette |

---

### OBS-CTR-002 — Incomplete Contract

| Alan | Değer |
|------|-------|
| **ID** | OBS-CTR-002 |
| **Amaç** | Zorunlu sözleşme alanlarının eksikliği |
| **Tanım** | Teslim, kapsam, garanti, fiyat kalemi structured'da yok (checklist) |
| **Kaynak Eventler** | `terms_proposed`, `terms_accepted` |
| **Metricler** | field completeness |
| **Signal'lar** | OBS-CTR-001 ile örtüşür; SIG-NEG-003 ile birleşebilir |
| **Context** | `contract_type`, `deliverable` |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | Mikro iş için minimal sözleşme yeterli |
| **False Negative** | Eksiklik düz metinde gizli |

---

### OBS-EVD-001 — Weak Evidence Chain

| Alan | Değer |
|------|-------|
| **ID** | OBS-EVD-001 |
| **Amaç** | Signal üretimi için event zincirinin kopuk veya seyrek olması |
| **Tanım** | Kritik aşamalarda event boşluğu; metric güvenilirliği düşük |
| **Kaynak Eventler** | timeline gaps |
| **Metricler** | `last_event_age_seconds`, missing expected event types |
| **Signal'lar** | SIG-ACT-001 |
| **Context** | `time_profile` |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | Sessizlik = kötü niyet |
| **False Negative** | Sahte event (gelecekte integrity sprint) |

---

### OBS-HIS-004 — Sparse History

| Alan | Değer |
|------|-------|
| **ID** | OBS-HIS-004 |
| **Amaç** | Actor geçmişi seyrek (az iş, uzun aralık) |
| **Tanım** | `transactions` ≥1 ama < eşik; zaman aralığı geniş, örneklem düşük |
| **Kaynak Eventler** | actor room agregasyonu |
| **Metricler** | transaction count, time since first event |
| **Signal'lar** | SIG-BEH-003 (düşük confidence) |
| **Context** | `actor_history: novice` |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | Düşük aktivite = kötü actor |
| **False Negative** | Çok işlem, hepsi iptal |

---

### OBS-CTR-003 — Low Contract Stability

| Alan | Değer |
|------|-------|
| **ID** | OBS-CTR-003 |
| **Amaç** | Kabul sonrası sözleşme istikrarsızlığı |
| **Tanım** | `SIG-CTR-002` yok (stability yok) + post-acceptance changes |
| **Kaynak Eventler** | `terms_accepted`, `terms_updated` |
| **Metricler** | post-acceptance change count > 0 |
| **Signal'lar** | SIG-CTR-002 (ters), SIG-SCP-001 ile küme |
| **Context** | `CTX-B2B` — değişiklik change order ile olabilir |
| **Explainable** | Evet |
| **Replayable** | Evet |
| **Karar üretir mi** | Hayır |
| **False Positive** | Resmi change order süreci |
| **False Negative** | Değişiklik dispute ile zorla |

---

### Observation indeksi

| ID | Name |
|----|------|
| OBS-REV-001 | High Revision Pattern |
| OBS-DEL-001 | Delivery Uncertainty |
| OBS-CTR-001 | Contract Ambiguity |
| OBS-TIM-001 | Frequent Deadline Changes |
| OBS-SET-001 | Settlement Instability |
| OBS-NEG-001 | High Negotiation Complexity |
| OBS-HIS-001 | Low Historical Evidence |
| OBS-HIS-002 | First-Time Transaction |
| OBS-HIS-003 | Limited Collaboration History |
| OBS-CTX-001 | High Context Uncertainty |
| OBS-SCP-001 | Repeated Scope Change |
| OBS-CTR-002 | Incomplete Contract |
| OBS-EVD-001 | Weak Evidence Chain |
| OBS-HIS-004 | Sparse History |
| OBS-CTR-003 | Low Contract Stability |

---

## 5. Observation Birleşimleri

### Neden tek observation yeterli değil?

Tek sinyal veya tek observation **bağlamdan kopuk** alarm üretebilir (`TRUST_ONTOLOGY.md` §5–6). Birleşim, **desen** görünürlüğünü artırır — yine de karar üretmez.

### Observation Cluster (Risk puanı yok)

**Observation Cluster**, birden fazla `observation_id`'nin **mantıksal paketi**dir. Cluster:

- Skor üretmez
- Öncelik sırası (rank) üretmez — yalnızca **birlikte bulunma** listeler
- `cluster_id`, `observations[]`, `shared_context`, `cluster_confidence` (min confidence kuralı)

#### Küme örneği 1 — `CLUSTER-MUD-001` (Müzakere belirsizliği)

| Observation | Rol |
|-------------|-----|
| OBS-NEG-001 | Yüksek müzakere karmaşıklığı |
| OBS-REV-001 | Yüksek revizyon |
| OBS-CTR-001 | Sözleşme belirsizliği |

**Okuma (nötr):** "Müzakere ve sözleşme yapısı birlikte detay kontrolü gerektirebilir." — **Karar yok.**

#### Küme örneği 2 — `CLUSTER-DEL-001` (Teslim belirsizliği)

| Observation | Rol |
|-------------|-----|
| OBS-DEL-001 | Teslim belirsizliği |
| OBS-TIM-001 | Sık deadline değişimi |
| OBS-CTR-002 | Eksik sözleşme alanları |

**Context:** `CTX-DIG` → çerçeve sıkı; `CTX-CON` → çerçeve geniş.

#### Küme örneği 3 — `CLUSTER-EVD-001` (Kanıt yetersizliği)

| Observation | Rol |
|-------------|-----|
| OBS-HIS-001 | Düşük geçmiş kanıtı |
| OBS-HIS-002 | İlk işlem |
| OBS-CTX-001 | Yüksek context belirsizliği |
| OBS-EVD-001 | Zayıf event zinciri |

**Okuma:** "Gözlemler sınırlı veriye dayanır; confidence düşük." — Alarm değil, **şeffaflık**.

#### Küme örneği 4 — `CLUSTER-SET-001` (Settlement gözlemi)

| Observation | Rol |
|-------------|-----|
| OBS-SET-001 | Settlement istikrarsızlığı |
| OBS-EVD-001 | Zayıf kanıt (opsiyonel) |

**Context:** Recovery engine kaynaklı ise actor'a **yüklenmez**.

### Birleşim kuralları

| Kural | Açıklama |
|-------|----------|
| **C1** | Cluster üyelerinin evidence chain'i ayrı ayrı korunur |
| **C2** | Cluster otomatik aksiyon tetiklemez |
| **C3** | Cluster confidence = min(member confidence) |
| **C4** | Çelişen observation'lar aynı cluster'da **listelenir**, biri susturulmaz |

---

## 6. Explainability

### Zorunlu geri izleme zinciri

```
Risk Observation (observation_id)
    ↓ basis.observations / signals[]
Context (dimensions, categories, taxonomy_version)
    ↓
Signal (signal_id, metrics, events[])
    ↓
Metric (name, value)
    ↓
Event (event_type, created_at, room_id, idempotency)
    ↓
Raw Evidence (domain event store — read only)
```

### Paket şeması (kavramsal, implementasyon değil)

Her observation çıktısı taşır:

- `observation_id`, `model_version`
- `statement` (nötr cümle)
- `confidence`: low | medium | high
- `signals`: [{ signal_id, … }]
- `context`: { dimensions, categories, notes[] }
- `events`: [{ event_type, … }] (denormalize referans)
- `assumptions`: [ontology/catalog assumption refs]

### Black Box Observation yasağı

| Yasak | Açıklama |
|-------|----------|
| Gizli kohort | Hangi kıyas grubu kullanıldığı gizlenemez |
| ML-only observation | Train edilmiş model çıktısı observation ID'si olmadan |
| LLM-generated observation | Deterministik katmanda yasak |
| Observation without signal | Signal atlama |

---

## 7. Risk Engine Yasakları

Risk Engine ve Risk Observation katmanı **asla**:

| # | Yasak |
|---|-------|
| 1 | Kişilik analizi |
| 2 | Niyet tahmini |
| 3 | Dolandırıcı etiketi |
| 4 | İyi insan / kötü insan kararı |
| 5 | Otomatik trust score değişikliği |
| 6 | Otomatik ceza |
| 7 | Otomatik işlem durdurma |
| 8 | Hesap kapatma |
| 9 | Blacklist |
| 10 | Whitelist |
| 11 | Finansal tavsiye |
| 12 | Hukuki tavsiye |
| 13 | Ticari tavsiye |
| 14 | "İşlemi yap / yapma" önerisi |

**İzinli dil örnekleri:** "Şu kanıtlar mevcut", "Bu bağlamda şu desen gözlemlendi", "Veri yetersizliği nedeniyle confidence düşük."

**Yasak dil örnekleri:** "Ödemeyin", "Kabul etmeyin", "Dolandırıcı olabilir", "Güvenilir değil."

---

## 8. Risk Observation Manifestosu

Bu bölüm Zinesh Risk katmanının **bağlayıcı mühendislik ilkelerini** tanımlar.

### Evidence Before Intelligence

**İlke:** Event → Metric → Signal → Context → Risk Observation → AI.

**Neden:** Atlama, sahte kanıt ve düzeltilemeyen LLM hataları üretir. Escrow Stable Core'un güveni ayrı; intelligence kanıt zincirine bağlı olmalı.

### Context Before Risk

**İlke:** Risk Observation, Context vektörü olmadan (veya `unknown` ile açıkça işaretlenmiş) üretilmez; `context_required` signal'lar için zorunlu.

**Neden:** Aynı sayı farklı sektörde zıt anlam taşır (`CONTEXT_TAXONOMY.md` §5–6). Bağlamsız risk false positive üretir.

### Explain Before Decision

**İlke:** Kullanıcıya sunulan her risk gözlemi, karar öncesi **açıklanabilir paket** halinde; gizli özet yok.

**Neden:** Karar kullanıcıda; bilgi asimetrisi güveni zedeler. Regülasyon ve ürün etiği.

### Human Before AI

**İlke:** AI Explanation observation'ı **değiştirmez**; son karar insan.

**Neden:** LLM halüsinasyonu risk katmanına sızmamalı. AI yardımcı, hakem değil.

### Read Only Intelligence

**İlke:** Risk üretimi engine mutasyonu yok.

**Neden:** Intelligence hatası para veya oda state'ini bozmamalı (`TRUST_INTELLIGENCE_ARCHITECTURE.md`).

### Stable Core

**İlke:** Escrow Engine (`escrow_room_lib.php`, `wallet_lib.php`) risk çıktısına **bağlı değildir**.

**Neden:** Settlement invariant'ları (S1 INV-A4 vb.) intelligence'dan bağımsız doğrulanabilir kalmalı.

### Composable Intelligence

**İlke:** Signal ve observation'lar cluster'lanabilir; monolitik "risk skoru" yok.

**Neden:** Açıklanabilirlik ve kısmi kapatılabilirlik (tek observation tipi devre dışı).

### Deterministic Analysis

**İlke:** Risk Observation katmanında LLM yok; replay mümkün.

**Neden:** Denetim, test, dispute sonrası inceleme.

### Privacy First

**İlke:** Actor verisi self-access ve sanitize paket kurallarına uyar; risk paketi PII sızdırmaz.

**Neden:** `TRUST_ONTOLOGY.md` Privacy First; Enterprise'da rol bazlı görünürlük — gizli boyut yok.

### Verifiable Output

**İlke:** Üçüncü taraf (operasyon, denetim) observation'ı event timeline ile **doğrulayabilir**.

**Neden:** "Black box risk" platform güvenini yok eder.

---

## 9. Roadmap

```
Trust Ontology
        ↓  (kavramlar, FP/FN, trust tanımı)
Trust Signal Catalog
        ↓  (signal_id, evidence chain, context_required)
Context Taxonomy
        ↓  (boyutlar, kategoriler, yorum matrisi)
Risk Observation Model  ← bu belge
        ↓  (observation_id, cluster, manifesto)
Risk Engine
        ↓  (deterministik üretim runtime — gelecek sprint)
AI Copilot
        ↓  (observation paketini açıklar)
Enterprise Observability
        ↓  (portföy düzeyi cluster agregasyonu)
Federated Trust Network
        ↓  (opt-in aggregate segment — bireysel observation dışarı çıkmaz)
```

### Veri aktarımı

| Kaynak | Hedef | Aktarılan |
|--------|-------|-----------|
| Ontology | Signal Catalog | Signal vs karar ayrımı, FP/FN sınıfları |
| Signal Catalog | Context Taxonomy | `context_required`, signal→context matrisi |
| Context Taxonomy | Risk Model | Boyut kodları, kategori ID'leri, çakışma kuralları |
| Risk Model | Risk Engine | `observation_id` tanımları, cluster kuralları, yasaklar |
| Risk Engine | AI Copilot | Sanitize observation paketi |
| Risk Engine | Enterprise | Agregasyon: cluster frekansı, sektör kırılımı |
| Enterprise | Federated | Yalnızca aggregate, opt-in |

**Not:** "Risk Engine" implementasyon sprint'i bu model belgesini **kodlamaz**; model onaylandıktan sonra ayrı açılır.

---

## 10. Living Document

Risk Observation Model **yaşayan referanstır**.

### Genişleme tetikleyicileri

| Tetikleyici | Eylem |
|-------------|-------|
| Yeni Signal (`TRUST_SIGNAL_CATALOG` amend) | İlgili observation girdisi güncelle |
| Yeni Context boyutu/kategori | Observation context bölümü genişlet |
| Yeni sektör / iş modeli | Cluster örnekleri + FP/FN notları |
| Yeni domain event | Yeni observation tipi (ontology uyumu) |
| Operasyonel öğrenim | FP/FN matrisi clarifikasyonu — skor değil, tanım |

### Versionlama

| Alan | Kural |
|------|-------|
| `model_version` | Semver: 1.0, 1.1, 2.0 |
| `observation_id` | Stabil; anlam değişirse yeni ID, eski `deprecated` |
| `cluster_id` | Üye observation listesi değişince cluster versiyon notu |
| Uyumluluk | Major: yeni zorunlu alan; Minor: yeni observation; Patch: FP/FN metin |

### Amend süreci (kavramsal)

1. Ontology + Catalog + Context uyum kontrolü
2. Yeni observation şablonu doldurma
3. Manifesto yasakları ihlal kontrolü
4. Engineering + Product (+ Legal)
5. `model_version` artışı
6. Risk Engine implementasyon sprint'i (ayrı)

---

## Stable Core İlişkisi

Risk Observation Model:

- Escrow state machine, settlement, recovery, dispute **değiştirmez**.
- Trust Metrics / Actor Trust v0.1 API'lerini **kırmaz** — observation ayrı tüketim katmanıdır.
- Engine'e geri besleme (feedback loop) **yoktur**.

---

## Çapraz Referans

| Belge | Bu modeldeki rol |
|-------|------------------|
| `TRUST_ONTOLOGY.md` | Trust tanımı, FP/FN, bağlam zorunluluğu |
| `TRUST_SIGNAL_CATALOG.md` | Signal girdileri, `SIG-*` referansları |
| `CONTEXT_TAXONOMY.md` | `CTX-*`, boyut kodları, çakışma kuralları |
| `TRUST_INTELLIGENCE_ARCHITECTURE.md` | Katman sırası, Risk Engine konumu |

---

## Belge Onayı

| Rol | Ad | Tarih | Onay |
|-----|-----|-------|------|
| Engineering | | | |
| Product | | | |
| Legal / Compliance | | | |

---

*Bu belge Zinesh Trust Intelligence ekosisteminin resmi Risk Observation referansıdır. Implementasyon, API, depolama ve JSON şemaları bu belgenin kapsamı dışındadır.*
