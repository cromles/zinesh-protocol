# Context Taxonomy

> **Bu doküman bir implementasyon planı değildir.**
>
> Bu doküman, Trust Signal'ların hangi bağlamlarda nasıl yorumlanacağını tanımlar.
>
> Context, Risk değildir. Karar değildir. Trust Score değildir. AI yorumu değildir.
>
> Context yalnızca Signal'ın doğru yorumlanmasını sağlar.
>
> Son karar her zaman kullanıcıya aittir.

**Taxonomy versiyonu:** 0.1  
**Tarih:** 2026-08-07  
**Sprint:** S2.3 — Context Taxonomy (dokümantasyon only)  
**Üst belgeler:** `docs/TRUST_ONTOLOGY.md`, `docs/TRUST_SIGNAL_CATALOG.md`, `docs/TRUST_INTELLIGENCE_ARCHITECTURE.md`

---

## 1. Amaç

### Context nedir?

**Context (Bağlam)**, bir Trust Signal gözleminin **hangi çerçevede okunacağını** tanımlayan, çok boyutlu ve **açıklanabilir metadata modelidir**. Context, signal'ın sayısal değerini değiştirmez; gözleme **nötr yorumlama notları** ekler.

Form:

> **Context** = { boyut₁, boyut₂, …, boyutₙ } → Interpretation Frame

Örnek:

> Signal: "5 sözleşme versiyonu"  
> Context: `{ iş_türü: yazılım, karmaşıklık: yüksek, süre: uzun_vadeli }`  
> Yorum çerçevesi: "Bu sektörde revizyon sıklığı genellikle yüksek kabul edilir; gözlem tek başına olumsuz değildir."

### Context ne değildir?

| Değildir | Açıklama |
|----------|----------|
| **Risk** | Risk istatistiksel kıyas katmanıdır (S3); context onun girdisidir |
| **Karar** | Engel, onay, ödeme, dispute sonucu değildir |
| **Trust Score** | Tek sayılı skor değildir; skor üretmez |
| **AI yorumu** | LLM çıktısı değildir; AI context'i **okur**, üretmez |
| **Signal** | Signal gözlemdir; context çerçevedir |
| **Etiket** | "Şüpheli iş", "güvenilir sektör" normatif sınıf değildir |
| **Ham event** | Event değiştirilmez, yeniden yazılmaz |

### Signal neden tek başına yeterli değildir?

Aynı metric değeri farklı iş ortamlarında **zıt anlamlara** gelebilir (`TRUST_ONTOLOGY.md` §7). Örneğin `counter_offer_count: 4`:

- Yazılım projesinde: keşif ve scope netleştirme
- İkinci el telefon satışında: anlaşmazlık veya belirsizlik göstergesi **olabilir** (hüküm değil, dikkat)

Signal yalnızca **"4 karşı teklif var"** der. **"Bu iyi mi kötü mü?"** sorusu context olmadan yanıtlanamaz — ve ontologi gereği **otomatik yanıtlanmamalıdır**.

### Risk Engine neden Context'e ihtiyaç duyar?

Risk Engine (S3) signal'ları kohort ve actor geçmişiyle kıyaslar. Kıyas **bağlamsız** yapılırsa false positive üretir:

- Actor'ün tüm işlerini tek havuzda toplamak → yazılım + ikinci el karışır
- "Ortalama tamamlama süresi" → inşaat ile dijital teslimat karşılaştırılamaz

Context, risk gözleminin **hangi kohort** ile kıyaslandığını açıklar: "Benzer iş türü + benzer tutar bandı + benzer süre modeli."

### Trust Intelligence neden Context olmadan eksik kalır?

Trust Intelligence signal üretir; context olmadan:

1. UI ve Copilot signal'ı **yanlış tonla** sunar (alarm veya rahatlık)
2. `context_required: true` signal'lar (`TRUST_SIGNAL_CATALOG.md`) eksik kalır
3. Explainability zinciri signal'da durur; kullanıcı "peki bu iş için normal mi?" sorusuna yanıt alamaz
4. Enterprise Observability sektör kırılımı yapamaz

**Context, Intelligence'ın kullanıcıya faydalı olmasının ön koşuludur** — karar mekanizması değil.

---

## 2. Context Yaşam Döngüsü

```
Event          → Kayıtlı domain olayı
    ↓
Metric         → Deterministik sayım/süre/oran
    ↓
Signal         → Nötr gözlem cümlesi + evidence chain
    ↓
Context        → Yorumlama çerçevesi (metadata)
    ↓
Risk           → İstatistiksel risk gözlemi (S3)
    ↓
AI Explanation → Doğal dil açıklama/öneri (S4)
```

### Katman görevleri

| Katman | Görev | Signal'ı değiştirir mi? | Event'i değiştirir mi? |
|--------|-------|-------------------------|------------------------|
| Event | Ne oldu | — | — |
| Metric | Say | Hayır | Hayır |
| Signal | Gözlemle | — | Hayır |
| **Context** | **Çerçevele** | **Hayır** | **Hayır** |
| Risk | Kıyasla | Hayır | Hayır |
| AI Explanation | Anlat | Hayır | Hayır |

### Context'in sınırları

- **Ham event'i değiştirmez** — geçmişe dönük düzenleme yok.
- **Signal'ı değiştirmez** — `counter_offer_count: 4` kalır; context yalnızca `interpretation_notes` ekler.
- **Yorumlama çerçevesi sağlar** — "bu gözlem bu iş türünde ne anlama **gelebilir**" (olasılık dili, hüküm dili değil).

### Evidence Before Intelligence

Context, signal'dan **sonra** gelir; event'ten **doğrudan** türetilmez.

```
Event → Metric → Signal → Context → Risk → AI
```

**Yasaklar:**

- `Event → Context` (metric ve signal atlanması)
- `LLM → Context` (AI context üretmez; mevcut taxonomy boyutlarını **seçer ve açıklar**)
- Context'in signal metric değerini "düzeltmesi"

Context boyutları **oda oluşturma / sözleşme structured terms / actor profil** gibi mevcut kayıtlardan deterministik olarak **atanabilir** (gelecek implementasyon sprint'i); bu atama yine event→metric→signal zincirinden **bağımsız bir metadata katmanıdır** ve gözlem sayısını değiştirmez.

---

## 3. Context Boyutları

Context **çok boyutlu vektör**dür. Tek boyut işlemi tam açıklamaz (`§6`).

### Boyut sözlüğü

| Boyut | Kod | Açıklama | Örnek değerler |
|-------|-----|----------|----------------|
| **İş Türü** | `job_type` | Platformda yapılan işin genel sınıfı | `software`, `design`, `consulting`, `physical_goods` |
| **Sektör** | `sector` | Ekonomik sektör veya dikey | `fintech`, `ecommerce`, `construction`, `creative` |
| **Ürün / Hizmet** | `offering` | Somut deliverable | `source_code`, `logo`, `video_edit`, `used_device` |
| **İş Karmaşıklığı** | `complexity` | Kapsam ve belirsizlik düzeyi | `low`, `medium`, `high`, `enterprise` |
| **Teslim Modeli** | `delivery_model` | Nasıl teslim edilir | `digital_instant`, `digital_iterative`, `physical_shipping`, `on_site` |
| **Taraf İlişkisi** | `party_relationship` | Tarafların önceki ilişkisi | `first_time`, `repeat_pair`, `same_org`, `marketplace_stranger` |
| **İşlem Geçmişi** | `actor_history` | Actor platform deneyimi | `first_transaction`, `novice`, `experienced`, `high_volume` |
| **İşlem Tutarı** | `amount_band` | TRY cinsinden bant | `micro`, `standard`, `high`, `enterprise` |
| **Zaman Faktörü** | `time_profile` | Süre beklentisi | `same_day`, `short_term`, `long_term`, `milestone_based` |
| **Coğrafi Faktör** | `geo` | Tarafların coğrafyası | `domestic`, `cross_border`, `unknown` |
| **Regulatory Context** | `regulatory` | Regülasyon yoğunluğu | `standard`, `regulated_sector`, `cross_border_compliance` |
| **Payment Method** | `payment` | Ödeme kanalı (escrow içi) | `tl_escrow_usdt`, `tl_mode` (platform mevcut modları) |
| **Contract Type** | `contract_type` | Sözleşme yapısı | `fixed_price`, `milestone`, `hourly_estimated`, `open_ended` |
| **Deliverable Type** | `deliverable` | Çıktı türü | `digital_asset`, `physical_product`, `service_hours`, `ip_transfer` |
| **Transaction Frequency** | `frequency` | Actor iş sıklığı | `one_off`, `occasional`, `regular`, `agency_portfolio` |

### Boyut atama ilkeleri (kavramsal)

| İlke | Açıklama |
|------|----------|
| **Declarative öncelik** | Kullanıcı/oda oluşturma sırasında seçilen iş türü structured terms'e yazılıysa o kaynak önceliklidir |
| **Unknown kabulü** | Boyut bilinmiyorsa `unknown` — uydurma yok |
| **Çoklu değer** | Bir boyut tek değer; birden fazla boyut birlikte çalışır |
| **Determinism** | Aynı oda metadata → aynı context vektörü |

---

## 4. Context Kategorileri

Context kategorileri, boyutların **kullanıcıya yakın paketlenmiş profilleridir**. Bir oda birden fazla kategori profiline **yakın** olabilir; kategori tek boyut değildir.

### Birincil kategori listesi (v0.1)

| Kategori ID | Ad | Tipik boyut kombinasyonu |
|-------------|-----|--------------------------|
| `CTX-SW` | Freelance Yazılım | `job_type:software`, `delivery_model:digital_iterative`, `complexity:medium-high` |
| `CTX-DSG` | Grafik Tasarım | `offering:logo/branding`, `delivery_model:digital`, `complexity:medium` |
| `CTX-VID` | Video Prodüksiyon | `offering:video`, `delivery_model:milestone`, `time_profile:medium` |
| `CTX-CNS` | Danışmanlık | `job_type:consulting`, `deliverable:service_hours`, `contract_type:hourly_estimated` |
| `CTX-PHY` | Fiziksel Ürün | `delivery_model:physical_shipping`, `deliverable:physical_product` |
| `CTX-SEC` | İkinci El Ticaret | `offering:used_device`, `complexity:low`, `party_relationship:marketplace_stranger` |
| `CTX-B2B` | Kurumsal B2B | `complexity:enterprise`, `amount_band:high`, `contract_type:milestone` |
| `CTX-ONE` | Tek Seferlik Hizmet | `frequency:one_off`, `time_profile:short_term` |
| `CTX-LNG` | Uzun Süreli Proje | `time_profile:long_term`, `contract_type:milestone` |
| `CTX-DIG` | Dijital Teslimat | `delivery_model:digital_instant`, `deliverable:digital_asset` |
| `CTX-CON` | İnşaat / Fiziksel Proje | `sector:construction`, `delivery_model:on_site`, `time_profile:long_term` |
| `CTX-AGY` | Ajans / Portföy | `frequency:agency_portfolio`, `party_relationship:repeat_pair` |

### Kategori genişletme

Yeni kategori eklemek **taxonomy amend** gerektirir; signal tanımı değişmez. Kategori yalnızca yorumlama rehberidir.

---

## 5. Signal → Context İlişkisi

Aşağıda `TRUST_SIGNAL_CATALOG.md` signal'ları için **yorumlama çerçevesi** verilir. Tüm ifadeler **"olabilir"** dilindedir — hüküm değildir.

### SIG-NEG-001 — Frequent Revisions

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Freelance Yazılım** (`CTX-SW`) | Yüksek revizyon sıklığı **beklenen davranış olabilir**; scope refinement normal. |
| **Uzun Süreli Proje** (`CTX-LNG`) | Versiyon artışı milestone yapısıyla uyumlu olabilir. |
| **İkinci El Ticaret** (`CTX-SEC`) | Çok revizyon **dikkat gerektiren gözlem olabilir**; basit ürün satışında nadir. |
| **Tek Seferlik Hizmet** (`CTX-ONE`) | Yüksek revizyon, basit iş beklentisiyle **çelişebilir** — kullanıcı sözleşmeyi kontrol etmeli. |

### SIG-NEG-002 — Counter Offer Frequency

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Kurumsal B2B** (`CTX-B2B`) | Çok turlu müzakere **yaygın olabilir**. |
| **İkinci El Ticaret** (`CTX-SEC`) | Fiyat pazarlığı normal; aşırı tur **belirsizlik göstergesi olabilir**. |
| **Danışmanlık** (`CTX-CNS`) | Saatlik/kapsam pazarlığı beklenen olabilir. |

### SIG-TIME-001 — Fast Agreement

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Dijital Teslimat** (`CTX-DIG`) | Mikro işlerde hızlı kabul **normal olabilir**. |
| **Kurumsal B2B** (`CTX-B2B`) | Çok hızlı kabul, detaylı inceleme ihtiyacı **artırabilir** (bilgi, hüküm değil). |
| **Taraf İlişkisi: repeat_pair** | Hız **beklenen** olabilir. |

### SIG-TIME-002 — Long Negotiation

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Kurumsal B2B** | Uzun müzakere **normal olabilir**. |
| **Tek Seferlik Hizmet** | Uzun müzakere, basit iş için **olağandışı olabilir**. |

### SIG-TIME-003 — Delivery Delay

| Context | Yorum çerçevesi |
|---------|-----------------|
| **İnşaat / Fiziksel Proje** (`CTX-CON`) | Gecikme sektör normları içinde **olabilir** (sözleşme tarihi varsa ona bakılır). |
| **Dijital Teslimat** (`CTX-DIG`) | Kısa döngülü dijital işte gecikme **dikkat çağırıcı olabilir**. |
| **Video Prodüksiyon** (`CTX-VID`) | Revizyon turu nedeniyle gecikme **yorumlanabilir** — milestone context gerekir. |

### SIG-TIME-004 — Missed Deadline

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Structured deadline var** | Gözlem **objektif**; context sektörü nasıl yorumlayacağını belirler. |
| **Deadline yok** | Signal üretilmemeli veya confidence Low — context eksik. |

### SIG-DSP-001 — Dispute Frequency

| Context | Yorum çerçevesi |
|---------|-----------------|
| **İkinci El Ticaret** | Dispute oranı platform ortalamasıyla kıyas **anlamlı olabilir**. |
| **Tüm context'ler** | Dispute açan tarafın rolü signal'da **ayırt edilmez** — mağdur da dispute açar. |

### SIG-SET-001 — Successful Settlement Pattern

| Context | Yorum çerçevesi |
|---------|-----------------|
| **actor_history: first_transaction** | Oran **anlamsız olabilir** (n küçük). |
| **Agency Portföy** | Yüksek tamamlama oranı **beklenen** profil olabilir. |

### SIG-BEH-001 — First Transaction

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Tüm** | Veri yetersizliği gözlemi; **risk etiketi değil**. |
| **amount_band: high** | Kullanıcı ekstra dikkat **isteyebilir** — karar kullanıcıda. |

### SIG-BEH-002 — Repeat Collaboration

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Freelance Yazılım** | Tekrarlayan eşleşme **olağan** olabilir. |
| **Marketplace stranger** | Repeat yoksa normal; repeat varsa güven **hükmü değil**, ilişki gözlemi. |

### SIG-CTR-001 — High Contract Volatility

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Yazılım + Uzun Proje** | Tutar/scope değişimi **beklenebilir**. |
| **İkinci El + Düşük karmaşıklık** | Volatilite **olağandışı olabilir**. |

### SIG-CTR-002 — Contract Stability

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Kurumsal B2B** | Kabul sonrası değişiklik yok **pozitif süreç göstergesi olabilir** (hüküm değil). |

### SIG-NEG-003 — Low Negotiation Activity

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Dijital Teslimat / Mikro** | Düşük tur **normal**. |
| **B2B / Yüksek tutar** | Düşük tur, yetersiz inceleme **riski olabilir** — bilgilendirme. |

### SIG-BEH-003 — Completion Consistency

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Karışık iş türleri** | Tutarlılık metriği **yanıltıcı olabilir** — iş türüne göre segment gerekir. |

### SIG-SET-002 — Escrow Completion Ratio

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Aktif devam eden işler** | Oran geçici olarak **düşük görünebilir**. |

### SIG-SET-003 — Settlement Failure Observed

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Engine recovery** | Kullanıcı hatası değil, **infra gözlemi** olabilir (`SIG-ACT-002` ile birlikte). |

### SIG-ACT-001 — Platform Silence Period

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Uzun Süreli Proje** | Uzun sessizlik **beklenen** olabilir. |
| **Dijital anlık teslim** | Sessizlik **dikkat çağırıcı olabilir**. |

### SIG-ACT-002 — Escrow Recovery Observed

| Context | Yorum çerçevesi |
|---------|-----------------|
| **Tüm** | Actor davranışı değil, **sistem olayı**; risk actor'a yüklenmemeli. |

---

## 6. Context Çakışmaları

### Çok boyutluluk

Context **tek katmanlı değildir**. Bir işlem eşzamanlı olarak birden fazla profilde değerlendirilir:

```
Kurumsal B2B          → amount, contract, complexity
    +
Freelance Yazılım     → job_type, delivery
    +
Uzun Süreli Proje     → time_profile
    +
Uluslararası Teslimat → geo, regulatory
    +
Yüksek Tutar          → amount_band
```

**Tek bir kategori işlemi tam açıklayamaz.** Context vektörünün tamamı Risk Engine ve Copilot'a iletilir.

### Çakışma örnekleri

#### Örnek 1: Yüksek revizyon — çelişen okuma

| Boyut | Etki |
|-------|------|
| `CTX-SW` (yazılım) | Revizyon normal **olabilir** |
| `amount_band: enterprise` | Revizyon beklenen **olabilir** |
| `CTX-ONE` (tek seferlik) | Aynı revizyon sayısı **olağandışı olabilir** |

**Çözüm:** Çakışma **hüküm üretmez**. Çıktı paketi tüm ilgili çerçeve notlarını listeler; confidence düşürülür; Copilot: "Farklı bağlamlarda farklı okunabilir; sözleşme kapsamını kontrol edin."

#### Örnek 2: Hızlı anlaşma + yüksek tutar

| Boyut | Etki |
|-------|------|
| `repeat_pair` | Hız normal |
| `amount_band: high` + `first_transaction` | Hız, inceleme ihtiyacını **artırabilir** |

**Çözüm:** Boyutlar **birbirini tamamlar**; en kısıtlayıcı bilgi notu (veri yetersizliği, yüksek tutar) öne çıkarılır — yine karar değil.

#### Örnek 3: Delivery delay — sektör çatışması

| Boyut | Etki |
|-------|------|
| `CTX-CON` (inşaat) | Gecikme toleransı yüksek **olabilir** |
| `CTX-DIG` (dijital) | Aynı gün sayısı gecikme **ciddi olabilir** |

### Çakışma ele alma kuralları (kavramsal)

| Kural | Açıklama |
|-------|----------|
| **K1 — Liste, birleştirme** | Tüm geçerli çerçeve notları pakette kalır |
| **K2 — Hüküm yok** | "Kazanmış" tek yorum seçilmez |
| **K3 — Confidence** | Çatışma varsa confidence **düşer** |
| **K4 — Öncelik: veri** | `unknown` boyut varsa alarm tonu **yükseltilmez** |
| **K5 — İnsan** | Copilot çatışmayı **açıkça söyler** |

---

## 7. Context İlkeleri

### Context kişilik analizi değildir

Boyutlar iş, sözleşme ve platform davranışıyla ilgilidir; karakter, psikoloji, demografi yoktur.

### Context etiketleme değildir

"Şüpheli iş", "premium müşteri" gibi tek kelimelik sınıflar taxonomy'de yoktur. Yalnızca yapılandırılmış boyutlar ve kategori profilleri.

### Context otomatik karar değildir

Hiçbir boyut kombinasyonu settlement, dispute veya erişim **tetiklemez**.

### Context otomatik risk değildir

Risk Engine ayrı katmandır; context risk **girdisidir**, risk **çıktısı değildir**.

### AI yalnızca yorumlama yardımcısıdır

AI:

- Context **üretmez** (taxonomy'den **seçer ve açıklar**)
- Context'i **değiştirmez**
- Kullanıcıya çerçeve notlarını **doğal dilde özetler**

---

## 8. Explainability

### Geriye izlenebilir zincir

Her Risk gözlemi (S3) ve AI Explanation (S4) şu paketi taşımalıdır:

```
risk_observation_id / explanation_id
  → signals: [ { signal_id, observation, metrics, events } ]
  → context: {
        dimensions: { job_type, sector, ... },
        categories: [ CTX-SW, CTX-B2B, ... ],
        interpretation_notes: [ ... ],
        taxonomy_version: "0.1"
      }
  → conflicts: [ optional frame conflicts ]
  → confidence
```

### Black Box Context yasağı

| Yasak | Açıklama |
|-------|----------|
| Gizli boyut | Kullanıcıya gösterilmeyen context |
| LLM-invented sector | Modelin uydurduğu sektör |
| Context'siz risk | Risk observation context referansı olmadan |

### Her Context açıklanabilir

Kullanıcı "bu yorum hangi iş türüne göre?" diye sorduğunda:

- Boyut değerleri
- Kaynak (oda alanı / structured terms / kullanıcı seçimi)
- Taxonomy versiyonu

gösterilebilir olmalıdır.

---

## 9. Tasarım İlkeleri

### Context Aware

Signal paketleri context vektörü olmadan `context_required` signal'lar için **eksik** sayılır.

### Explainable

Her boyut değeri için `source` ve `taxonomy_version` izlenebilir.

### Evidence Based

Context, event→metric→signal zincirinden **sonra** eklenir; event'ten doğrudan "riskli sektör" çıkarılmaz.

### Human Controlled

Context kullanıcıya sunulur; kullanıcı oda oluştururken iş türünü **yanlış seçmişse** düzeltme kullanıcıdadır — sistem sessizce override etmez.

### Replayable

Aynı oda metadata + aynı taxonomy versiyonu → aynı context vektörü.

### Composable

Boyutlar ve kategoriler birleşir; çakışmalar listelenir (`§6`).

### Deterministic

Context atama kuralları (gelecek sprint) LLM içermez.

### Read Only

Context üretimi escrow/wallet/trust storage'a yazmaz.

### No Hidden Context

Risk veya Copilot'a giden tüm context kullanıcı/operatör tarafından **erişilebilir** olmalıdır (Enterprise'da rol bazlı görünürlük ayrı politika — gizli boyut yok).

### Evidence Before Intelligence

```
Event → Metric → Signal → Context → Risk → AI Explanation
```

AI, context'i **okur**; signal veya metric **üretmez**.

---

## 10. Roadmap

```
Trust Ontology (S2.1)
        ↓
Trust Signal Catalog (S2.2)
        ↓
Context Taxonomy (S2.3)  ← bu belge
        ↓
Risk Engine (S3)
        ↓
AI Copilot (S4)
        ↓
Enterprise Observability (S5)
        ↓
Federated Trust Network (S7)
```

### Context'in mimarideki rolü

| Aşama | Context kullanımı |
|-------|-------------------|
| **Signal Catalog** | `context_required` flag; signal başına yorum matrisi |
| **Context Taxonomy** | Boyut sözlüğü, kategori profilleri, çakışma kuralları |
| **Risk Engine** | Kohort seçimi: "benzer context vektörü" ile kıyas |
| **AI Copilot** | Signal + context → açıklama; "bu iş türünde X normal olabilir" |
| **Enterprise Observability** | Portföy kırılımı: sektör, B2B, tutar bandı |
| **Federated Network** | Yalnızca aggregate context segmentleri (sektör düzeyi); bireysel context dışarı çıkmaz |

**Kritik:** S3 Risk Engine, S2.3 olmadan **bağlamsız kıyas** yapar ve false positive üretir. Context Taxonomy, Catalog ile Engine arasındaki **zorunlu halkadır**.

---

## 11. Living Document

Context Taxonomy **tamamlanmış bir belge değildir**.

Genişleme tetikleyicileri:

| Tetikleyici | Örnek |
|-------------|-------|
| Yeni sektör | SaaS implementasyon, oyun geliştirme |
| Yeni iş modeli | Abonelik tabanlı escrow, milestone-only |
| Yeni işlem tipi | Grup satın alma, çok taraflı oda |
| Yeni davranış kalıbı | Yeni domain event → yeni signal → yeni context matrisi satırı |
| Yeni regülasyon | KVKK, sektörel lisans, cross-border kural |
| Yeni ticaret modeli | B2B marketplace, white-label |

### Amend süreci (kavramsal)

1. Ontoloji ve Signal Catalog uyumu
2. Yeni boyut/kategori tanımı + signal matrisi güncelleme
3. FP/FN notları
4. Engineering + Product (+ Legal gerektiğinde)
5. `taxonomy_version` artışı

---

## 12. Future Evolution

Context Taxonomy ileride şu yönlerle **genişletilebilir** (vizyon):

| Yön | Açıklama |
|-----|----------|
| **Sektörel profiller** | Dikey derinlik: e-ticaret vs sağlık vs inşaat alt şablonları |
| **Ülke bazlı farklılıklar** | `geo` + yerel iş kültürü notları (hüküm değil, beklenti bandı) |
| **Hukuki farklılıklar** | `regulatory` boyutu genişler; platform hukuki tavsiye **vermez** |
| **İş modeli farklılıkları** | Ajans, marketplace, direct hire |
| **Yeni işlem tipleri** | Çok taraflı, abonelik, kısmi teslim |

### Değişmeyen ilke

> **Context hiçbir zaman Trust Signal'ın yerine geçmez.**

Signal: **"Ne oldu?"** (ölçülebilir)  
Context: **"Hangi çerçevede okunmalı?"** (yorumlama)  
Risk: **"Bu kohortla kıyaslandığında ne görülüyor?"** (istatistik)  
AI: **"Bunu insan dilinde nasıl anlatırız?"** (açıklama)

Context yalnızca Signal'ın **doğru yorumlanmasına** yardımcı olur.

---

## Stable Core İlişkisi

Context Taxonomy:

- Escrow Engine state machine'i **değiştirmez**.
- Signal metric değerlerini **değiştirmez**.
- Mevcut oda alanlarından (`title`, `description`, structured terms) **okuma** vizyonu tanımlar; yeni zorunlu engine alanı **önermez** (implementasyon sprint kararı).

---

## Belge Onayı

| Rol | Ad | Tarih | Onay |
|-----|-----|-------|------|
| Engineering | | | |
| Product | | | |
| Legal / Compliance | | | |

---

## Çapraz Referans

| Belge | İlişki |
|-------|--------|
| `TRUST_ONTOLOGY.md` | Trust tanımı, bağlam boyutu §7 |
| `TRUST_SIGNAL_CATALOG.md` | Signal tanımları, `context_required`, §5 matris |
| `TRUST_INTELLIGENCE_ARCHITECTURE.md` | Katman sırası, Evidence Before Intelligence |

---

*Bu belge Zinesh Trust Intelligence ekosisteminin çekirdek referanslarından biridir. Implementasyon, API ve depolama bu belgenin kapsamı dışındadır.*
