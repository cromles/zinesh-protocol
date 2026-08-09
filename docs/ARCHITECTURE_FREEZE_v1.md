# Architecture Freeze v1.0

## Architecture Status

**Zinesh Trust Intelligence**

**Architecture v1.0**

**STATUS: FROZEN**

---

Bu belge, Zinesh Trust Intelligence mimarisinin **v1.0** sürümünün dondurulduğunu ilan eder. Bundan sonraki tüm implementasyonlar bu mimariye uymak zorundadır. Mimari değişiklikleri normal geliştirme sürecinin parçası değildir ve **ayrı Architecture Review** gerektirir.

**Freeze tarihi:** 2026-08-07  
**Sprint:** S3.1A.4 — Architecture Freeze v1.0  
**Üst referans:** `docs/TRUST_DOMAIN_MODEL.md` (resmi Ubiquitous Language)  
**Governance türü:** Architecture Constitution

---

## 1. Freeze Kapsamı

Aşağıdaki bileşenler **Architecture Freeze v1.0** kapsamında dondurulmuştur.

### Stable Core

Escrow Engine (`escrow_room_lib.php`, `wallet_lib.php`) ve doğrudan para/oda state mutasyonu yapan invariant-governed kritik yollar. Trust Intelligence bu çekirdeğe **yazmaz**, settlement/recovery/dispute kararlarını **üretmez**. Intelligence kapalıyken escrow normal çalışır.

**Kaynak:** `TRUST_INTELLIGENCE_ARCHITECTURE.md` §1, Stable Core Dokunulmazlık Beyanı

---

### Trust Intelligence Architecture

Katman sırası, bounded context sınırları, Read Only ilkesi, roadmap vizyonu ve Stable Core ile ilişki. Events → Timeline → AI Context → Trust Metrics → Actor Trust → Trust Intelligence (Signal) → Risk Engine → AI Copilot → Enterprise Observability → Federated Trust Network.

**Kaynak:** `docs/TRUST_INTELLIGENCE_ARCHITECTURE.md`

---

### Trust Ontology

"Güven" kavramının mühendislik tanımı: Trust = bilgi, karar değil; reputation, puanlama veya para değil. Observable, Explainable, Deterministic, Measurable ilkeleri; varsayımlar ve false positive/negative farkındalığı.

**Kaynak:** `docs/TRUST_ONTOLOGY.md`

---

### Trust Signal Catalog

Resmi `SIG-*` sinyal tanımları, kategoriler, evidence chain kuralları, `context_required` bayrakları ve signal confidence (Evidence Completeness bileşeni). Signal atomik gözlemdir; karar üretmez.

**Kaynak:** `docs/TRUST_SIGNAL_CATALOG.md`

---

### Context Taxonomy

Çok boyutlu context vektörü, `CTX-*` kategorileri, signal→context matrisi, çakışma kuralları. Context yorumlama çerçevesidir; Risk değildir, Signal'ı değiştirmez.

**Kaynak:** `docs/CONTEXT_TAXONOMY.md`

---

### Risk Observation Model

Resmi `OBS-*` risk observation tanımları, Observation Cluster kuralları (`CLUSTER-*`), manifesto, 14 yasak, explainability şeması. Risk Observation birleşik gözlemdir; karar, ceza, skor veya olasılık değildir.

**Kaynak:** `docs/RISK_OBSERVATION_MODEL.md`

---

### Trust Domain Model

Tüm kavramların resmi Ubiquitous Language tanımı, katman kuralları, domain invariants, terminoloji birliği, Evidence Chain ve Confidence = Evidence Completeness. **Bundan sonraki tüm kod, API ve katmanlar bu modele uymak zorundadır.**

**Kaynak:** `docs/TRUST_DOMAIN_MODEL.md`

---

### İlişkili — dondurulmamış, uyum zorunlu

`docs/OBSERVATION_ENGINE_IMPLEMENTATION_PLAN.md` bir **implementasyon planıdır**; mimari değildir. Risk Engine v0.1 implementasyonu bu freeze'e ve `TRUST_DOMAIN_MODEL.md` terminolojisine uymak zorundadır. Plan ile frozen terminoloji çelişirse **Domain Model esastır**.

---

## 2. Frozen Terminology

Bundan sonraki tüm implementasyonların resmi terminoloji referansı:

| Kavram | Resmi Kullanım | Not |
|--------|----------------|-----|
| **Domain Event** | ✓ | Trust BC'de tek event türü; append-only kanıt kaynağı |
| **Event** | ✓ | Domain Event ile eşanlamlı (Trust Intelligence bağlamında) |
| **Timeline** | ✓ | Domain Event'lerden türetilen kronolojik dizi |
| **Metric** | ✓ | Deterministik sayım/süre/oran |
| **Trust Metrics** | ✓ | Oda bazlı metrik paketi/katmanı |
| **Trust Signal** | ✓ | `SIG-*` catalog atomik gözlem |
| **Signal** | ✓ | Trust Signal kısaltması (aynı anlam) |
| **Context** | ✓ | Çok boyutlu yorumlama çerçevesi |
| **AI Context** | ✓ | Sanitize oda bağlam paketi |
| **Observation (Gözlem)** | ✓ | Ontoloji üst kavramı (soyut) |
| **Risk Observation** | ✓ | `OBS-*` catalog birleşik gözlem çıktısı |
| **Observation Cluster** | ✓ | Birden fazla Risk Observation mantıksal paketi |
| **Evidence** | ✓ | Kayıtlı kanıt birimi |
| **Evidence Chain** | ✓ | Risk Observation → Signal → Metric → Event zinciri |
| **Explainability** | ✓ | Zorunlu `basis` ve geri izlenebilirlik |
| **Confidence** | ✓ | Anlam: **Evidence Completeness** |
| **Actor Trust** | ✓ | Kullanıcı çoklu oda agregasyon metrikleri |
| **Trust Intelligence** | ✓ | Signal üretim mimari katmanı |
| **Risk Engine** | ✓ | Risk Observation üreten runtime katman |
| **AI Copilot** | ✓ | Doğal dil açıklama katmanı |
| **AI Explanation** | ✓ | AI Copilot çıktı türü |
| **Human Decision** | ✓ | Nihai insan/operatör kararı |
| **Enterprise Observability** | ✓ | Portföy/organizasyon gözlemi katmanı |
| **Federated Trust Network** | ✓ | Opt-in aggregate trust (uzun vadeli) |
| **Stable Core** | ✓ Frozen | Escrow + wallet invariant çekirdeği |
| **Evidence Before Intelligence** | ✓ | Katman atlama yasağı (değiştirilemez ilke) |
| **Human in Control** | ✓ | Otomatik karar yasağı (değiştirilemez ilke) |
| **Read Only Intelligence** | ✓ | Intelligence yazmaz (değiştirilemez ilke) |

### Yasak / geçersiz terminoloji (yeni kullanım)

| Kullanım | Durum | Resmi karşılık |
|----------|-------|----------------|
| Observation Engine (domain/runtime) | ✗ | **Risk Engine** |
| Enterprise Intelligence | ✗ | **Enterprise Observability** |
| Confidence = Probability | ✗ | **Evidence Completeness** |
| Confidence = AI Confidence | ✗ | **Evidence Completeness** |
| Confidence = Risk Confidence | ✗ | **Evidence Completeness** |
| Risk Score (karar anlamında) | ✗ | **Risk Observation** (gözlem paketi) |
| Trust Score (black-box) | ✗ | Çok boyutlu gözlem + `basis` |

---

## 3. Frozen Architecture Chain

Resmi üretim ve tüketim zinciri — **değiştirilemez**:

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

### Bağlayıcı kurallar

1. **Hiçbir katman zinciri atlayamaz.**
2. **Ters akış yasaktır** (ör. Risk Observation → Signal üretimi).
3. **AI Copilot** ham Event okuyamaz; deterministic paketi değiştiremez.
4. **Human Decision** sistem tarafından otomatik üretilemez.
5. **Observation (Gözlem)** üst kavramdır; runtime'da ayrı katman değildir. Somut çıktılar: **Trust Signal** (atomik) ve **Risk Observation** (birleşik).

**Kaynak:** `TRUST_DOMAIN_MODEL.md` §2, §5

---

## 4. Architecture Invariants

Bu bölüm **Architecture Constitution** niteliğindedir. İhlal, Zinesh Trust Intelligence kimliğinin bozulması sayılır.

| # | İlke | Açıklama |
|---|------|----------|
| C1 | **Stable Core dokunulmazdır** | Intelligence escrow, wallet, settlement, recovery'ye yazmaz |
| C2 | **Intelligence Read Only'dir** | Trust stack mutasyon üretmez; hata boş paket döner |
| C3 | **Event geçmişi değiştirilemez** | Append-only; silme/yeniden yazma yok |
| C4 | **Evidence Before Intelligence** | Event → Metric → Signal → Context → Risk Observation zorunlu sıra |
| C5 | **Signal doğrudan Event'ten üretilemez** | Metric aracılığı zorunlu |
| C6 | **Explainability zorunludur** | `fired` Risk Observation boş `basis` taşıyamaz |
| C7 | **Evidence olmadan Risk Observation üretilemez** | Kanıt zinciri atlama yasak |
| C8 | **Context Risk değildir** | Context girdi; risk çıktısı değil |
| C9 | **Context Signal'ı değiştirmez** | Yorum çerçevesi; metric/signal değeri sabit |
| C10 | **Risk Observation karar değildir** | Onay, red, ödeme, engel üretmez |
| C11 | **Risk işlem durduramaz** | Otomatik ceza, blacklist, hesap kapatma yok |
| C12 | **AI karar vermez** | Copilot bilgilendirir; mutasyon tetiklemez |
| C13 | **AI Signal/Observation değiştirmez** | LLM deterministic core'u değiştiremez |
| C14 | **Human in Control** | Nihai karar insanda; otomatik aksiyon yok |
| C15 | **No Black Box** | ML-only veya LLM-generated observation yasak |
| C16 | **Deterministic core** | Aynı girdi + aynı versiyon → aynı Signal/Risk Observation |
| C17 | **Confidence = Evidence Completeness** | Olasılık, AI confidence veya risk skoru değil |
| C18 | **Trust bilgidir; para değildir** | Transfer edilemez; engine hatası para kaybına yol açmamalı |
| C19 | **Privacy First** | PII sızdırma yok; actor verisi self-access kurallarına uyar |
| C20 | **Kapatılabilirlik** | Intelligence kapalıyken escrow çalışır |

**Kaynak:** `TRUST_DOMAIN_MODEL.md` §6; `RISK_OBSERVATION_MODEL.md` §7–8

---

## 5. Governance Rules

### İzin verilen evrim (normal sprint kapsamında)

Aşağıdakiler **frozen mimariyi bozmadan** genişletilebilir:

| Genişleme | Koşul |
|-----------|-------|
| Yeni **Trust Signal** (`SIG-*`) | `TRUST_SIGNAL_CATALOG.md` amend; ontology uyumu |
| Yeni **Context** boyutu/kategori (`CTX-*`) | `CONTEXT_TAXONOMY.md` amend |
| Yeni **Risk Observation** (`OBS-*`) | `RISK_OBSERVATION_MODEL.md` amend |
| Yeni **Observation Cluster** | Risk Model §5 kurallarına uygun |
| Yeni **sektör / iş profili** | Taxonomy + catalog FP/FN notları |
| Yeni **domain event tipi** | Stable Core sprint (emit); ardından catalog güncelleme |
| Risk Engine / AI Copilot / Enterprise **implementasyonu** | Frozen chain ve invariants korunarak |

**Amend sırası (zorunlu):** Ontology → Signal Catalog → Context Taxonomy → Risk Observation Model → Domain Model referans kontrolü (`RISK_OBSERVATION_MODEL.md` §10).

---

### Yasak evrim (Architecture Review olmadan)

| Değişiklik | Durum |
|------------|-------|
| **Stable Core** değişikliği (intelligence gerekçesiyle) | ✗ — ayrı Engine sprint |
| **Terminoloji** değişikliği | ✗ — ACP + Architecture Review |
| **Trust Domain Model** invariant değişikliği | ✗ — ACP + Architecture Review |
| **Architecture Chain** değişikliği | ✗ — ACP + Architecture Review |
| Katman atlama (ör. Event → Risk Observation) | ✗ |
| Intelligence → escrow yazma | ✗ |
| Risk Observation → otomatik aksiyon | ✗ |

---

## 6. Development Workflow

Freeze sonrası resmi geliştirme akışı:

```
Problem
    ↓
Domain Review
    ↓
Architecture Compliance
    ↓
Implementation
    ↓
Regression
    ↓
Architecture Review
    ↓
Deploy
```

### Aşama tanımları

| Aşama | Amaç |
|-------|------|
| **Problem** | İş veya teknik ihtiyaç tanımı |
| **Domain Review** | `TRUST_DOMAIN_MODEL.md` ve ilgili catalog/taxonomy ile kavramsal uyum |
| **Architecture Compliance** | Frozen chain, invariants (§4), terminology (§2) kontrolü — **kod yazılmadan önce** |
| **Implementation** | Read Only, Stable Core izolasyonu, explainability zorunluluğu |
| **Regression** | Mevcut e2e + Stable Core invariant testleri; determinism |
| **Architecture Review** | Compliance kanıtı; invariant ihlali yok |
| **Deploy** | Engine ve intelligence ayrı deploy döngüleri mümkün |

**Kural:** Mimariye aykırı implementasyon merge/deploy edilemez. Architecture Compliance atlanamaz.

---

## 7. Roadmap After Freeze

Architecture Freeze v1.0 sonrası resmi katman implementasyon sırası (`TRUST_INTELLIGENCE_ARCHITECTURE.md` §10, `TRUST_DOMAIN_MODEL.md` §11):

```
Trust Signal Catalog + Trust Intelligence (Signal runtime)
    ↓
Risk Engine
    ↓
AI Copilot
    ↓
Enterprise Observability
    ↓
Federated Trust Network
```

### Katman özeti

| Sıra | Katman | Freeze sonrası durum |
|------|--------|----------------------|
| — | Trust Metrics, Actor Trust, AI Context, Timeline, Events | **Mevcut v0.1** (read-only) |
| 1 | **Trust Intelligence** (Signal emitter) | Implementasyon bekliyor |
| 2 | **Risk Engine** | Implementasyon bekliyor |
| 3 | **AI Copilot** | Roadmap S4 |
| 4 | **Enterprise Observability** | Roadmap S5 |
| 5 | **Federated Trust Network** | Roadmap S7 |

**Not:** AI Copilot ve Enterprise, Risk Engine çıktısı olmadan frozen chain'i ihlal eder. Sıra değiştirilemez.

---

## 8. Architecture Change Policy

### Normal sprintlerde

| Öğe | Değişir mi? |
|-----|-------------|
| Terminoloji | **Hayır** |
| Trust Domain Model (invariants, chain) | **Hayır** |
| Architecture v1.0 yapısı | **Hayır** |
| Signal/Observation/Context catalog **içerik** (yeni ID) | **Evet** (§5 amend kuralları) |
| Implementasyon | **Evet** (compliance ile) |

### Mimari değişiklik gerektiren durumlar

Aşağıdaki değişiklikler yalnızca:

1. **Architecture Change Proposal (ACP)** — değişiklik gerekçesi, invariant etkisi, geri alma planı  
2. **Architecture Review** — onay veya red  

ile yapılabilir. Normal feature sprint'i bu sürecin yerini alamaz.

**ACP kapsamına giren örnekler:**

- Architecture Chain'e yeni katman ekleme veya sıra değişikliği  
- Domain invariant ekleme, kaldırma veya gevşetme  
- Resmi terminoloji değişikliği  
- Confidence anlamının değiştirilmesi  
- Read Only veya Human in Control ilkesinin gevşetilmesi  
- Intelligence → Stable Core yazma izni  

**ACP kapsamı dışı (catalog amend):**

- Yeni `SIG-*`, `OBS-*`, `CTX-*` tanımı (mevcut amend süreci, §5)

---

## 9. Milestone Declaration

**Zinesh Trust Intelligence Architecture v1.0**, bu belgeyle birlikte **Frozen** ilan edilmiştir.

Bundan sonraki çalışmaların amacı yeni mimari üretmek değil; bu mimariyi **güvenli**, **açıklanabilir** ve **sürdürülebilir** şekilde hayata geçirmektir.

| Alan | Beyan |
|------|-------|
| Mimari sürüm | **v1.0 FROZEN** |
| Resmi dil | `docs/TRUST_DOMAIN_MODEL.md` |
| Anayasa | Bu belge §4 Architecture Invariants |
| Sonraki öncelik | Risk Engine implementasyonu (frozen chain uyumlu) |
| Mimari değişiklik | Yalnızca ACP + Architecture Review |

---

## Referans Dökümanlar (Frozen Set)

| Belge | Rol |
|-------|-----|
| `TRUST_INTELLIGENCE_ARCHITECTURE.md` | Katman vizyonu ve roadmap |
| `TRUST_ONTOLOGY.md` | Güven ontologisi |
| `TRUST_SIGNAL_CATALOG.md` | Signal tanımları |
| `CONTEXT_TAXONOMY.md` | Context boyutları ve kurallar |
| `RISK_OBSERVATION_MODEL.md` | Risk Observation tanımları |
| `TRUST_DOMAIN_MODEL.md` | Ubiquitous Language (birincil referans) |
| `OBSERVATION_ENGINE_IMPLEMENTATION_PLAN.md` | Risk Engine v0.1 implementasyon planı (mimari değil) |

---

## Onay

| Rol | Ad | Tarih | İmza |
|-----|-----|-------|------|
| Engineering | | | |
| Product | | | |
| Architecture | | | |

---

*Bu belge Zinesh Trust Intelligence ekosisteminin resmi Architecture Constitution dokümanıdır. Freeze v1.0 sonrası tüm sprintler bu belgeye ve `TRUST_DOMAIN_MODEL.md`'ye tabidir.*
