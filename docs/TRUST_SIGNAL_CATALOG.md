# Trust Signal Catalog

> **Bu doküman bir implementasyon planı değildir.**
>
> Bu doküman, Zinesh Trust Intelligence sisteminin resmi sinyal kataloğunu tanımlar.
>
> Buradaki hiçbir signal tek başına karar üretmez.
>
> Her signal yalnızca kanıta dayalı gözlemsel bilgidir.
>
> Risk değildir. Karar değildir. AI yorumu değildir.
>
> Son karar daima kullanıcıya aittir.

**Katalog versiyonu:** 0.1  
**Tarih:** 2026-08-07  
**Sprint:** S2.2 — Trust Signal Catalog (dokümantasyon only)  
**Üst belgeler:** `docs/TRUST_ONTOLOGY.md`, `docs/TRUST_INTELLIGENCE_ARCHITECTURE.md`

---

## 1. Amaç

### Trust Signal nedir?

**Trust Signal**, platformda kayıtlı **domain event**'lerden türetilmiş **deterministik metric**'ler üzerine inşa edilen, doğal dilde ifade edilebilen **kanıta dayalı davranış gözlemidir**. Her signal bir `signal_id`, gözlem metni, evidence chain ve (gerektiğinde) bağlam notu taşır.

Form:

> **Signal** = Metric( Event[] ) + Context? → Observation

### Trust Signal ne değildir?

| Değildir | Açıklama |
|----------|----------|
| Ham event | Event kayıt birimidir; signal türevdir |
| Metric | Metric sayıdır; signal anlamlı gözlem cümlesidir |
| Risk | Risk istatistiksel bağlam katmanıdır (S3); signal onun girdisi olabilir |
| AI yorumu | LLM çıktısı non-deterministic ve en üst katmandır |
| Karar | Engel, ceza, ödeme, dispute sonucu değildir |
| Etiket | "Dolandırıcı", "güvenilir" normatif sınıflama değildir |

### Signal ile Metric arasındaki fark

| Metric | Signal |
|--------|--------|
| Sayısal, yapılandırılmış | Gözlemsel, insan-okunur |
| `counter_offer_count: 4` | "Bu odada 4 karşı teklif kaydı var." |
| Karşılaştırma ve hesap için | UI, Copilot ve Risk için anlam katmanı |
| Deterministik | Deterministik (metric'ten türetilir) |

**Kural:** Signal, metric'i **yorumlamaz**; metric değerini **nötr dilde raporlar**. Normatif yük signal metnine eklenmez.

### Signal ile Risk arasındaki fark

| Signal | Risk (gelecek katman) |
|--------|----------------------|
| "Dispute oranı %20 (n=5 işlem)" | "Bu tutar bandında dispute oranı actor ortalamasının üstünde" |
| Tek boyutlu gözlem | Çok sinyilli istatistiksel bağlam |
| Karar üretmez | Karar üretmez (ontoloji aynı) |
| Signal Catalog'da tanımlı | Risk Engine'de birleştirilir |

### Signal ile AI Yorumu arasındaki fark

| Signal | AI Explanation |
|--------|----------------|
| Deterministik | Non-deterministic (LLM) |
| `basis`: event + metric | `basis`: signal + risk + sanitize context |
| Replay edilebilir | Prompt/model versiyonuna bağlı |
| Catalog'da resmi tanım | Copilot katmanında; signal'ı **değiştirmez** |

### Signal ile karar arasındaki fark

Signal bilgi üretir. Karar (sözleşme onayı, ödeme serbest bırakma, dispute açma, iptal) **yalnızca kullanıcı** ve **Escrow Engine kuralları** ile oluşur. Signal hiçbir engine mutasyonunu tetiklemez.

---

## 2. Signal Yaşam Döngüsü

```
Event          → Domain olayı (append-only kayıt)
    ↓
Metric         → Deterministik sayım/süre/oran
    ↓
Signal         → Nötr gözlem cümlesi + evidence chain
    ↓
Context        → Sektör, iş türü, tutar bandı, ilişki boyutu
    ↓
Risk           → İstatistiksel risk gözlemi (S3)
    ↓
AI Explanation → Doğal dil özeti/öneri (S4)
```

### Katman görevleri

| Katman | Görev | Yazar mı? |
|--------|-------|-----------|
| **Event** | "Ne oldu?" — zaman damgalı kayıt | Engine (emit) |
| **Metric** | Event'lerden sayı/süre hesapla | Intelligence (read) |
| **Signal** | Metric'i gözleme dönüştür | Intelligence (read) |
| **Context** | Gözlemin hangi çerçevede okunacağı | Taxonomy (S2.3+) |
| **Risk** | Sinyalleri kohort/bağlamla kıyasla | Risk Engine (read) |
| **AI Explanation** | Kullanıcıya açıkla/öner | Copilot (read) |

### Evidence Before Intelligence

Signal **hiçbir zaman** ham event'ten doğrudan üretilmemelidir.

Zorunlu zincir:

```
Event₁ … Eventₙ  →  Metric  →  Signal
```

**Neden?**

1. **Determinism** — Aynı metric, aynı signal; event parsing tekrarlanabilir.
2. **Explainability** — Signal sorulduğunda önce metric, sonra event listesi gösterilir.
3. **Test edilebilirlik** — Metric birim testi; signal metric testinin üzerine.
4. **AI güvenliği** — LLM event log'u okumaz; sanitize signal paketi okur.

**Yasak:** `Event → Signal` (metric atlaması).

---

## 3. Signal Kategorileri

| Kategori | Kod | Kapsam |
|----------|-----|--------|
| **Negotiation Signals** | `NEG` | Müzakere, teklif, red, karşı teklif, değişiklik talebi |
| **Settlement Signals** | `SET` | Ödeme başlangıcı, tamamlama, başarısızlık, tamamlama oranı |
| **Time Signals** | `TIME` | Süre, gecikme, hızlı anlaşma, uzun müzakere |
| **Behavioral Signals** | `BEH` | Actor düzeyi pattern, tutarlılık, tekrar işbirliği |
| **Contract Signals** | `CTR` | Sözleşme versiyonu, volatilite, stabilite, eksik madde (yapısal) |
| **Dispute Signals** | `DSP` | İtiraz açma, çözüm, frekans |
| **Activity Signals** | `ACT` | Platform içi aktivite, sessizlik, recovery gözlemi |

Kategori, signal'ın **birincil veri kaynağını** tanımlar; bir signal birden fazla metric tüketebilir.

---

## 4. Signal Tanımları

### Confidence Level (tüm signal'lar için)

**Confidence**, gözlemin **veri güvenilirliğini** ifade eder — kararın veya riskin doğruluğunu değil.

| Seviye | Anlam |
|--------|-------|
| **Low** | Az event, eksik zaman damgası, tek örneklem (n<3) |
| **Medium** | Yeterli oda içi veri; actor agregasyonu sınırlı |
| **High** | Zengin event zinciri + actor geçmişi (n≥5 benzer iş) |

---

### SIG-NEG-001 — Frequent Revisions

| Alan | Değer |
|------|-------|
| **Signal Name** | Frequent Revisions |
| **Kategori** | Negotiation (`NEG`) |
| **Amaç** | Sözleşme metninde sık güncelleme/revizyon gözlemi |
| **Açıklama** | Belirli eşik üzerinde `terms_updated`, `terms_proposed` veya sözleşme versiyon artışı kaydı var. |
| **Kaynak Eventler** | `terms_proposed`, `terms_updated`, `counter_offer_created` |
| **Kullanılan Metricler** | `terms_proposed_count`, `negotiation_rounds`, contract `version_number` max |
| **Evidence Chain** | Event → `negotiation_rounds` / version count → "N revizyon/versiyon kaydı" |
| **Context Required?** | **Evet** — yazılım vs basit ürün |
| **Explainable?** | Evet |
| **Confidence Level** | Medium (oda içi); Low (actor agregasyonu n küçükse) |
| **Risk oluşturabilir mi?** | Evet (Risk Engine girdisi) |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | Profesyonel projede normal scope refinement |
| **False Negative** | Versiyon event'i emit edilmeden anlaşma değişikliği |

---

### SIG-NEG-002 — Counter Offer Frequency

| Alan | Değer |
|------|-------|
| **Signal Name** | Counter Offer Frequency |
| **Kategori** | Negotiation (`NEG`) |
| **Amaç** | Karşı teklif sıklığı gözlemi |
| **Açıklama** | `counter_offer_created` event sayısı eşiği aşıldı veya actor ortalamasının üstünde. |
| **Kaynak Eventler** | `counter_offer_created` |
| **Kullanılan Metricler** | `counter_offer_count`, `negotiation_rounds` |
| **Evidence Chain** | Event count → `counter_offer_count` → gözlem metni |
| **Context Required?** | Evet |
| **Explainable?** | Evet |
| **Confidence Level** | High (event açık); Medium (actor kıyası) |
| **Risk oluşturabilir mi?** | Evet |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | İlk kez çalışan tarafların fiyat keşfi |
| **False Negative** | Karşı teklif mesajla, event olmadan |

---

### SIG-TIME-001 — Fast Agreement

| Alan | Değer |
|------|-------|
| **Signal Name** | Fast Agreement |
| **Kategori** | Time (`TIME`) |
| **Amaç** | Tekliften kabul'e kısa süre gözlemi |
| **Açıklama** | `contract_created`/`terms_proposed` ile `terms_accepted` arası süre alt yüzdelik dilimde. |
| **Kaynak Eventler** | `terms_proposed`, `terms_accepted`, `contract_created` |
| **Kullanılan Metricler** | `acceptance_time`, `contract_creation_time`, süre farkı (saniye) |
| **Evidence Chain** | Event timestamps → duration metric → "X dakikada kabul" |
| **Context Required?** | Evet — basit iş vs karmaşık sözleşme |
| **Explainable?** | Evet |
| **Confidence Level** | Medium (timestamp eksikse Low) |
| **Risk oluşturabilir mi?** | Evet |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | "Şüpheli hız" — oysa taraflar önceden anlaşmış |
| **False Negative** | Uzun sessizlik sonrası tek tık kabul |

---

### SIG-TIME-002 — Long Negotiation

| Alan | Değer |
|------|-------|
| **Signal Name** | Long Negotiation |
| **Kategori** | Time (`TIME`) |
| **Amaç** | Müzakere fazının uzun sürmesi gözlemi |
| **Açıklama** | İlk tekliften `terms_accepted` veya `escrow_locked`'a kadar geçen süre üst yüzdelik dilimde. |
| **Kaynak Eventler** | `terms_proposed`, `terms_accepted`, `escrow_locked` |
| **Kullanılan Metricler** | `negotiation_rounds`, `duration_seconds` (kabul öncesi segment) |
| **Evidence Chain** | Events → time metric → gözlem |
| **Context Required?** | Evet |
| **Explainable?** | Evet |
| **Confidence Level** | Medium |
| **Risk oluşturabilir mi?** | Evet |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | "Olumsuz" — oysa karmaşık B2B sözleşmesi |
| **False Negative** | Platform dışı müzakere |

---

### SIG-TIME-003 — Delivery Delay

| Alan | Değer |
|------|-------|
| **Signal Name** | Delivery Delay |
| **Kategori** | Time (`TIME`) |
| **Amaç** | Kilitlenme sonrası tamamlamaya kadar geçen sürenin uzun olması |
| **Açıklama** | `escrow_locked` ile `settlement_completed` / tamamlama onayı arası süre, sözleşmede tanımlı teslim süresini aşıyor **veya** kohort üst diliminde (teslim tarihi yoksa yalnızca kohort). |
| **Kaynak Eventler** | `escrow_locked`, `escrow_release_requested`, `settlement_completed` |
| **Kullanılan Metricler** | `duration_seconds`, structured_terms içi teslim alanı (varsa) |
| **Evidence Chain** | Events → duration → signal; structured term varsa karşılaştırma notu |
| **Context Required?** | **Evet** — teslim tarihi tanımlı mı? |
| **Explainable?** | Evet |
| **Confidence Level** | High (teslim tarihi structured'da); Low (tarih yoksa) |
| **Risk oluşturabilir mi?** | Evet |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | Sözleşmede teslim tarihi yokken "geç" demek |
| **False Negative** | Taraflar uzatmayı platform dışı mutabık kıldı |

---

### SIG-TIME-004 — Missed Deadline

| Alan | Değer |
|------|-------|
| **Signal Name** | Missed Deadline |
| **Kategori** | Time (`TIME`) |
| **Amaç** | Sözleşmede kayıtlı teslim/commitment tarihinin geçmiş olması |
| **Açıklama** | `structured_terms_json` içinde teslim tarihi var ve bugün > deadline; tamamlama yok veya deadline sonrası. |
| **Kaynak Eventler** | `terms_accepted`, `settlement_completed`; contract structured terms |
| **Kullanılan Metricler** | deadline field parse, `settlement_time` |
| **Evidence Chain** | Contract version → metric (deadline delta) → signal |
| **Context Required?** | Evet — deadline alanı dolu olmalı |
| **Explainable?** | Evet |
| **Confidence Level** | High (deadline structured'da); **signal üretilmez** (N/A) deadline yoksa |
| **Risk oluşturabilir mi?** | Evet |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | Yanlış parse edilmiş tarih formatı |
| **False Negative** | Teslim tarihi metinde serbest metin, structured'da yok |

---

### SIG-DSP-001 — Dispute Frequency

| Alan | Değer |
|------|-------|
| **Signal Name** | Dispute Frequency |
| **Kategori** | Dispute (`DSP`) |
| **Amaç** | Actor veya oda düzeyinde dispute sıklığı |
| **Açıklama** | `dispute_opened` sayısı / tamamlanan iş sayısı oranı veya mutlak adet eşiği. |
| **Kaynak Eventler** | `dispute_opened`, `dispute_resolved` |
| **Kullanılan Metricler** | `dispute_count`, `dispute_resolved`, actor `disputes` / `transactions` |
| **Evidence Chain** | Events → counts → oran → gözlem |
| **Context Required?** | Evet — dispute açan tarafın rolü okunmamalı (nötr sayım) |
| **Explainable?** | Evet |
| **Confidence Level** | Low (n<3); Medium/High (n büyük) |
| **Risk oluşturabilir mi?** | Evet |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | Haklı mağdur sık dispute açar |
| **False Negative** | Platform dışı çözüm, dispute açılmadan |

---

### SIG-SET-001 — Successful Settlement Pattern

| Alan | Değer |
|------|-------|
| **Signal Name** | Successful Settlement Pattern |
| **Kategori** | Settlement (`SET`) |
| **Amaç** | Actor'ün geçmiş işlerinde başarılı settlement oranı |
| **Açıklama** | `settlement_completed` / kapanmış oda oranı yüksek (eşik veya kohort üstü). |
| **Kaynak Eventler** | `settlement_completed`, oda `status: completed` |
| **Kullanılan Metricler** | `successful_settlement`, actor `successful_settlements` / `transactions` |
| **Evidence Chain** | Events → settlement metric → pattern gözlemi |
| **Context Required?** | Hayır (sayım nötr); **Evet** (yorum için iş türü) |
| **Explainable?** | Evet |
| **Confidence Level** | Low (n<3); High (n≥10) |
| **Risk oluşturabilir mi?** | Evet (düşük frekans riski değil, bilgi) |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | "Güvenilir kullanıcı" hükmü |
| **False Negative** | Az işlemle yüksek oran (n=1) |

---

### SIG-BEH-001 — First Transaction

| Alan | Değer |
|------|-------|
| **Signal Name** | First Transaction |
| **Kategori** | Behavioral (`BEH`) |
| **Amaç** | Actor için ilk tamamlanan veya ilk kilitlenen escrow işlemi |
| **Açıklama** | Actor geçmişinde `transactions` = 0 veya ilk `settlement_completed`. |
| **Kaynak Eventler** | `settlement_completed`, `escrow_locked` |
| **Kullanılan Metricler** | actor `transactions`, oda sayısı |
| **Evidence Chain** | Actor room list → count metric → "ilk işlem" bayrağı |
| **Context Required?** | Hayır |
| **Explainable?** | Evet |
| **Confidence Level** | High (sayım kesin) |
| **Risk oluşturabilir mi?** | Evet ("veri yetersizliği" risk gözlemi) |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | "Riskli" etiketi — herkes bir kez yenidir |
| **False Negative** | Önceki hesap değişikliği (platform dışı kimlik) |

---

### SIG-BEH-002 — Repeat Collaboration

| Alan | Değer |
|------|-------|
| **Signal Name** | Repeat Collaboration |
| **Kategori** | Behavioral (`BEH`) |
| **Amaç** | Aynı employer-worker çiftinin birden fazla oda geçmişi |
| **Açıklama** | Aynı `employerUid` + `workerUid` ile ≥2 kapanmış veya kilitli oda. |
| **Kaynak Eventler** | `escrow_locked`, `settlement_completed` (oda agregasyonu) |
| **Kullanılan Metricler** | peer pair room count |
| **Evidence Chain** | Room index → pair count metric → gözlem |
| **Context Required?** | Hayır |
| **Explainable?** | Evet |
| **Confidence Level** | High (kayıtlı odalar) |
| **Risk oluşturabilir mi?** | Evet (düşük risk bilgisi) |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | "Güvenilir ortak" hükmü |
| **False Negative** | Farklı hesaplarla aynı kişiler |

---

### SIG-CTR-001 — High Contract Volatility

| Alan | Değer |
|------|-------|
| **Signal Name** | High Contract Volatility |
| **Kategori** | Contract (`CTR`) |
| **Amaç** | Sözleşme tutarı veya ana şartların versiyonlar arası sık değişmesi |
| **Açıklama** | Ardışık contract version'larda `amount_try` veya yapısal alan değişim sayısı eşik üstü. |
| **Kaynak Eventler** | `terms_updated`, `counter_offer_created`, contract versions |
| **Kullanılan Metricler** | version count, amount delta count |
| **Evidence Chain** | Versions → volatility metric → gözlem |
| **Context Required?** | **Evet** |
| **Explainable?** | Evet |
| **Confidence Level** | Medium |
| **Risk oluşturabilir mi?** | Evet |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | Scope değişen proje |
| **False Negative** | Metin değişir, amount sabit |

---

### SIG-CTR-002 — Contract Stability

| Alan | Değer |
|------|-------|
| **Signal Name** | Contract Stability |
| **Kategori** | Contract (`CTR`) |
| **Amaç** | Kabul sonrası sözleşme metninde değişiklik olmaması |
| **Açıklama** | `terms_accepted` sonrası `terms_updated` / `counter_offer_created` yok. |
| **Kaynak Eventler** | `terms_accepted`, `terms_updated`, `counter_offer_created` |
| **Kullanılan Metricler** | post-acceptance change count (=0) |
| **Evidence Chain** | Event timeline filter → count → "kabul sonrası değişiklik yok" |
| **Context Required?** | Hayır |
| **Explainable?** | Evet |
| **Confidence Level** | High |
| **Risk oluşturabilir mi?** | Evet (stabilite bilgisi) |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | — |
| **False Negative** | Değişiklik dispute ile zorla |

---

### SIG-NEG-003 — Low Negotiation Activity

| Alan | Değer |
|------|-------|
| **Signal Name** | Low Negotiation Activity |
| **Kategori** | Negotiation (`NEG`) |
| **Amaç** | Müzakere turu sayısının düşük olması |
| **Açıklama** | `negotiation_rounds` ≤ 1 ve tek versiyon kabul. |
| **Kaynak Eventler** | `terms_proposed`, `terms_accepted` |
| **Kullanılan Metricler** | `negotiation_rounds`, version count |
| **Evidence Chain** | Events → negotiation_rounds → gözlem |
| **Context Required?** | Evet — basit işte normal |
| **Explainable?** | Evet |
| **Confidence Level** | High |
| **Risk oluşturabilir mi?** | Evet |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | "Şüpheli basitlik" — standart mikro iş |
| **False Negative** | Çok mesaj, az formal event |

---

### SIG-BEH-003 — Completion Consistency

| Alan | Değer |
|------|-------|
| **Signal Name** | Completion Consistency |
| **Kategori** | Behavioral (`BEH`) |
| **Amaç** | Actor'ün tamamlama sürelerinin dar bantta olması |
| **Açıklama** | `average_completion_time` varyansı düşük; süreler kohort içinde tutarlı. |
| **Kaynak Eventler** | `settlement_completed`, time metrics per room |
| **Kullanılan Metricler** | actor `average_completion_time`, std dev (gelecek metric) |
| **Evidence Chain** | Per-room duration → actor stats → consistency gözlemi |
| **Context Required?** | Evet — farklı iş türleri karışmamalı |
| **Explainable?** | Evet |
| **Confidence Level** | Low (n<5); Medium/High (n büyük) |
| **Risk oluşturabilir mi?** | Evet |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | "Güvenilir" hükmü |
| **False Negative** | Az örneklem |

---

### SIG-SET-002 — Escrow Completion Ratio

| Alan | Değer |
|------|-------|
| **Signal Name** | Escrow Completion Ratio |
| **Kategori** | Settlement (`SET`) |
| **Amaç** | Başlatılan escrow'ların tamamlanma oranı |
| **Açıklama** | `escrow_locked` olan odalar / `settlement_completed` veya `completed` status oranı. |
| **Kaynak Eventler** | `escrow_locked`, `settlement_completed`, `settlement_failed`, `cancelled` |
| **Kullanılan Metricler** | locked count, completed count, ratio |
| **Evidence Chain** | Events → counts → ratio metric → gözlem |
| **Context Required?** | Hayır (oran nötr) |
| **Explainable?** | Evet |
| **Confidence Level** | Medium (actor); High (oda tek iş) |
| **Risk oluşturabilir mi?** | Evet |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | Aktif devam eden işler oranı düşürür |
| **False Negative** | İptal edilen işler paydasına dahil değilse |

---

### SIG-SET-003 — Settlement Failure Observed

| Alan | Değer |
|------|-------|
| **Signal Name** | Settlement Failure Observed |
| **Kategori** | Settlement (`SET`) |
| **Amaç** | Ödeme işleminin başarısız olması kaydı |
| **Açıklama** | `settlement_failed` event veya recovery sonrası partial state gözlemi. |
| **Kaynak Eventler** | `settlement_failed`, `escrow_recovered` |
| **Kullanılan Metricler** | `settlement_failed` flag, `reliability_signal` bileşeni (v0.1) |
| **Evidence Chain** | Event → boolean metric → gözlem |
| **Context Required?** | **Evet** — actor vs engine hatası ayrımı |
| **Explainable?** | Evet |
| **Confidence Level** | High (event varsa) |
| **Risk oluşturabilir mi?** | Evet |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | Actor reliability düşük sanılır — oysa engine TTL |
| **False Negative** | Fail event emit edilmeden sessiz stuck |

---

### SIG-ACT-001 — Platform Silence Period

| Alan | Değer |
|------|-------|
| **Signal Name** | Platform Silence Period |
| **Kategori** | Activity (`ACT`) |
| **Amaç** | Oda içinde uzun süre kayıtlı event/mesaj olmaması |
| **Açıklama** | Son event'ten bu yana X gün geçti; **yalnızca aktivite eksikliği** raporlanır. |
| **Kaynak Eventler** | herhangi domain event `created_at` max |
| **Kullanılan Metricler** | `last_event_age_seconds` |
| **Evidence Chain** | Events → max timestamp → age metric → gözlem |
| **Context Required?** | Hayır |
| **Explainable?** | Evet |
| **Confidence Level** | High (zaman); **yorum yok** |
| **Risk oluşturabilir mi?** | Evet (düşük) |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | "Kötü niyet" — tatil, dış iletişim |
| **False Negative** | — |

**Ontoloji notu:** Bu signal **asla** niyet çıkarmaz; yalnızca "platformda kayıt yok" der.

---

### SIG-ACT-002 — Escrow Recovery Observed

| Alan | Değer |
|------|-------|
| **Signal Name** | Escrow Recovery Observed |
| **Kategori** | Activity (`ACT`) |
| **Amaç** | TTL/system recovery'nin çalıştığı gözlemi |
| **Açıklama** | `escrow_recovered` event; payload'da `recovery_action` (ör. `settling_complete`). |
| **Kaynak Eventler** | `escrow_recovered` |
| **Kullanılan Metricler** | recovery count, recovery action type |
| **Evidence Chain** | Event → count → gözlem |
| **Context Required?** | Evet — engine operasyonu vs kullanıcı hatası |
| **Explainable?** | Evet |
| **Confidence Level** | High |
| **Risk oluşturabilir mi?** | Evet |
| **Tek başına karar üretebilir mi?** | **Hayır** |
| **False Positive** | Kullanıcı güvensiz sanılır — oysa infra |
| **False Negative** | Recovery event emit edilmezse |

---

### Katalog indeksi (hızlı referans)

| ID | Signal Name | Kategori |
|----|-------------|----------|
| SIG-NEG-001 | Frequent Revisions | NEG |
| SIG-NEG-002 | Counter Offer Frequency | NEG |
| SIG-NEG-003 | Low Negotiation Activity | NEG |
| SIG-TIME-001 | Fast Agreement | TIME |
| SIG-TIME-002 | Long Negotiation | TIME |
| SIG-TIME-003 | Delivery Delay | TIME |
| SIG-TIME-004 | Missed Deadline | TIME |
| SIG-DSP-001 | Dispute Frequency | DSP |
| SIG-SET-001 | Successful Settlement Pattern | SET |
| SIG-SET-002 | Escrow Completion Ratio | SET |
| SIG-SET-003 | Settlement Failure Observed | SET |
| SIG-BEH-001 | First Transaction | BEH |
| SIG-BEH-002 | Repeat Collaboration | BEH |
| SIG-BEH-003 | Completion Consistency | BEH |
| SIG-CTR-001 | High Contract Volatility | CTR |
| SIG-CTR-002 | Contract Stability | CTR |
| SIG-ACT-001 | Platform Silence Period | ACT |
| SIG-ACT-002 | Escrow Recovery Observed | ACT |

---

## 5. Context Bağımlılığı

Signal gözlemleri **bağlam olmadan normatif okunmamalıdır**. Context Taxonomy (gelecek belge) aşağıdaki boyutları sağlayacaktır; bu katalog hangi signal'ın context zorunlu olduğunu işaretler.

### Örnek: Yüksek revizyon

```
SIG-NEG-001 (Frequent Revisions)
        ↓
   Yazılım / tasarım projesi     → "Sektör normları içinde" (nötr)
        ↓
   İkinci el ürün / basit teslim  → "Bu iş türü için üst dilim" (dikkat çağrısı, hüküm değil)
```

### Bağlam boyutları (referans)

| Boyut | Etki |
|-------|------|
| **Sektör / iş türü** | Revizyon, süre, müzakere normalliği |
| **Tutar bandı** | İlk işlem + yüksek tutar = veri yetersizliği vurgusu |
| **İlişki** | Repeat collaboration signal bağlamı değiştirir |
| **Sözleşme yapısı** | Deadline, teslim alanı var/yok |
| **Oda durumu** | Dispute açıkken time signal'ları farklı okunur |

### Neden zorunlu?

Aynı metric değeri **farklı iş türlerinde** opposite anlama gelebilir. Context olmadan signal **false positive** üretir. Risk Engine ve Copilot context'i **metadata** olarak signal paketine ekler; signal metnini değiştirmez.

---

## 6. Signal Birleşimleri

Tek signal çoğu zaman yetersizdir. **Composable** ilkesi: birden fazla signal bir arada **daha zengin gözlem paketi** oluşturur — yine de **otomatik karar üretmez**.

### Örnek birleşim: Anlaşmazlık yoğunluğu gözlemi

```
SIG-DSP-001 (Dispute Frequency)
        +
SIG-NEG-001 (Frequent Revisions)
        +
SIG-TIME-003 (Delivery Delay)
        ↓
Gözlem paketi: "Bu actor geçmiş işlerinde dispute oranı yüksek;
                 bu odada revizyon sayısı üst dilimde;
                 kilitlenmeden bu yana geçen süre kohort üstünde."
        ↓
Risk Engine (S3): istatistiksel kıyas — hâlâ karar değil
        ↓
Copilot (S4): "Şu gözlemler mevcut; sözleşme teslim maddesini kontrol etmenizi öneririm."
```

### Birleşim kuralları

| Kural | Açıklama |
|-------|----------|
| **AND birleşimi** | Tüm signal'ların evidence chain'i ayrı ayrı gösterilir |
| **OR yasağı kararda** | "Herhangi biri → engelle" otomasyonu yok |
| **Confidence** | Birleşik paket confidence = en zayıf halka (min) |
| **Yasak** | Birleşimden "dolandırıcı" etiketi türetmek |

---

## 7. Explainability

Her signal şu **kanıt zincirini** taşımalıdır:

```
signal_id
  → observation_text
  → metrics: { metric_name: value, ... }
  → events: [ { event_type, created_at, count? }, ... ]
  → catalog_version
  → ontology_version
```

### Zorunluluklar

- **Black Box signal üretimi yasaktır** — metric atlaması, gizli ağırlık, LLM-only signal yok.
- Timeline'a geri izleme: kullanıcı event listesine inebilir.
- Copilot, signal üretmez; mevcut signal paketini **açıklar**.

### Örnek explainability çıktısı (kavramsal)

```
SIG-NEG-002 Counter Offer Frequency
  observation: "4 karşı teklif kaydı"
  metrics: { counter_offer_count: 4, negotiation_rounds: 6 }
  events: [ counter_offer_created ×4 ]
  confidence: High
```

---

## 8. Yasaklar

Signal sistemi **asla**:

| # | Yasak |
|---|-------|
| 1 | Kişilik analizi yapmaz |
| 2 | Niyet okumaz |
| 3 | İyi/kötü insan kararı vermez |
| 4 | Dolandırıcı etiketi üretmez |
| 5 | Otomatik ceza vermez |
| 6 | Otomatik işlem durdurmaz |
| 7 | Otomatik trust score değiştirir (engine veya kullanıcı skoru) |
| 8 | Hukuki yorum üretmez |

İhlal, signal değil **Trust dışı sistem** sayılır ve mimari review ile reddedilir.

---

## 9. Tasarım İlkeleri

### Explainable

Her signal `signal_id` + metric + event referansı ile açıklanır. Kullanıcı "neden bu gözlem var?" sorusuna yanıt alır.

### Deterministic

Aynı event seti + aynı catalog versiyonu → aynı signal seti. LLM signal üretiminde kullanılmaz.

### Read Only

Signal üretimi escrow, wallet, treasury, trust score storage'a yazmaz.

### Context Aware

`context_required: true` signal'lar bağlam metadata'sı olmadan Copilot'a **tek başına** sunulmaz (uyarı ile birlikte sunulabilir).

### Evidence Based

Event → Metric → Signal sırası zorunlu; Evidence Before Intelligence.

### Composable

Signal'lar paketlenebilir; paket karar üretmez, gözlem listesini zenginleştirir.

### Replayable

Event log replay ile signal seti yeniden üretilebilir (catalog versiyonu sabit).

### Human Controlled

Signal UI'da bilgi kartıdır; aksiyon kullanıcıda.

### No Black Box

Gizli model, gizli ağırlık, gizli eşik yok — eşikler catalog'da veya versiyonlu config belgesinde (implementasyon sprint'i) açık olmalıdır.

### Evidence Before Intelligence

```
Event → Metric → Signal → Context → Risk → AI Explanation
```

AI Explanation katmanı signal'ı **değiştiremez**, yalnızca açıklar.

---

## 10. Roadmap

```
Trust Ontology (S2.1)
        ↓
Trust Signal Catalog (S2.2) ← bu belge
        ↓
Context Taxonomy (S2.3)
        ↓
Risk Engine (S3)
        ↓
AI Copilot (S4)
        ↓
Enterprise Observability (S5)
        ↓
Federated Trust Network (S7)
```

| Aşama | Katalogdan faydalanma |
|-------|----------------------|
| **Context Taxonomy** | `context_required` boyutlarını resmileştirir; signal başına sektör matrisi genişler |
| **Risk Engine** | Signal ID'leri risk observation girdisi; FP/FN matrisi risk confidence'a akar |
| **AI Copilot** | Yalnızca catalog signal'larını açıklar; catalog dışı "sezgi" yasak |
| **Enterprise Observability** | Signal paketlerini portföy düzeyinde agregeler; yeni signal tanımı catalog amend ile |
| **Federated Trust Network** | Yalnızca catalog'da `federation_eligible: true` işaretlenecek signal'lar (gelecek amend) paylaşılır — v0.1'de işaret yok, varsayılan kapalı |

---

## 11. Living Document

Trust Signal Catalog **tamamlanmış bir belge değildir**.

Aşağıdaki durumlarda **katalog amend** (yeni versiyon) gerekir:

- Yeni sektör veya işlem tipi
- Yeni domain event (engine emit — ayrı sprint)
- Yeni davranış modeli gözlemi
- Yeni sözleşme structured alanı
- False positive / false negative öğrenimi (metin veya eşik clarifikasyonu — implementasyon değil, tanım güncellemesi)

### Versiyonlama

| Alan | Açıklama |
|------|----------|
| `catalog_version` | Bu belgenin semver'i (0.1, 0.2, …) |
| `signal_id` | Stabil tanımlayıcı; anlam değişirse yeni ID, eski deprecated |
| `deprecated` | Eski signal yeni versiyonda okunur ama üretilmez |

### Amend süreci (kavramsal)

1. Ontoloji uyumu kontrolü (`TRUST_ONTOLOGY.md`)
2. Mimari uyum (`TRUST_INTELLIGENCE_ARCHITECTURE.md`)
3. FP/FN matrisi güncelleme
4. Engineering + Product onayı
5. Implementasyon sprint'i **ayrı** açılır

---

## Stable Core İlişkisi

Bu katalog:

- Escrow Engine'e yazmaz.
- Yeni event **tanımlamaz** (event'ler engine/memory sorumluluğunda kalır).
- Mevcut v0.1 metric'lerle **uyumlu** tasarlanmıştır; genişleme catalog amend ile yapılır.

---

## Belge Onayı

| Rol | Ad | Tarih | Onay |
|-----|-----|-------|------|
| Engineering | | | |
| Product | | | |
| Legal / Compliance | | | |

---

*Bu belge Zinesh Trust Intelligence ekosisteminin resmi signal referansıdır. Implementasyon, API ve depolama bu belgenin kapsamı dışındadır.*
