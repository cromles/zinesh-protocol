# Observation Engine v0.1 — Implementation Plan

> **Bu doküman onay sonrası implementasyon sprint'inin teknik şartnamesidir.**
>
> Bu belge bir implementasyon **planıdır**; production kodu içermez.
>
> Onay öncesi kod, patch veya deploy yapılmaz.

**Plan versiyonu:** 0.1  
**Tarih:** 2026-08-07  
**Sprint:** S3.1A — Observation Engine Architecture Review  
**Hedef runtime:** Observation Engine v0.1 (PHP, read-only lib + e2e test)  
**Referans belgeler:** `TRUST_INTELLIGENCE_ARCHITECTURE.md`, `TRUST_ONTOLOGY.md`, `TRUST_SIGNAL_CATALOG.md`, `CONTEXT_TAXONOMY.md`, `RISK_OBSERVATION_MODEL.md`

---

## Özet

Observation Engine v0.1, mevcut **read-only** trust katmanlarından veri okuyarak `RISK_OBSERVATION_MODEL.md` içindeki observation tanımlarını **deterministik** üreten yeni bir PHP lib katmanıdır. Escrow Stable Core'a yazmaz, API endpoint içermez (v0.1 kapsamı), yalnızca `room_id` (+ opsiyonel `actor_id`) için observation paketi döner.

**Kapsam dışı (v0.1):** Risk Engine API, AI Copilot entegrasyonu, observation persistence/cache, Federated Trust, Enterprise agregasyon, escrow/wallet değişikliği.

---

## 1. Observation Engine Mimarisi — Katmanlar

```
┌─────────────────────────────────────────────────────────────┐
│  Observation Engine v0.1 (YENİ — orchestrator)                │
│  zinesh_observation_engine_for_room($roomId)                │
└───────────────────────────┬─────────────────────────────────┘
                            │ reads only
        ┌───────────────────┼───────────────────┐
        ▼                   ▼                   ▼
┌───────────────┐   ┌───────────────┐   ┌───────────────────┐
│ Signal Layer  │   │ Context Layer │   │ Observation Rules │
│ (YENİ)        │   │ (YENİ)        │   │ (YENİ — catalog)  │
└───────┬───────┘   └───────┬───────┘   └─────────┬─────────┘
        │                   │                     │
        └───────────────────┼─────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Mevcut read-only katmanlar (DEĞİŞMEZ)                        │
│  Trust Intelligence → Metrics                                 │
│  AI Context → room snapshot, behavior_stats, contract versions│
│  Domain Events → zinesh_domain_events_for_room                │
│  Timeline → zinesh_escrow_room_timeline (explainability)      │
│  Actor Trust → actor agregasyon (actor-scoped observations)   │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Escrow Stable Core (DOKUNULMAZ)                            │
│  escrow_room_lib.php, wallet_lib.php                          │
└─────────────────────────────────────────────────────────────┘
```

### Katman sorumlulukları

| Katman | Sorumluluk | v0.1 |
|--------|------------|------|
| **Evidence readers** | Domain events, timeline, AI context snapshot okuma | Mevcut lib'ler |
| **Metric provider** | `zinesh_trust_intelligence_metrics()` delegasyonu | Mevcut |
| **Signal emitter** | Metric → `SIG-*` catalog eşlemesi | **Yeni** |
| **Context resolver** | Oda metadata → `CONTEXT_TAXONOMY` boyut vektörü | **Yeni** |
| **Observation evaluator** | Signal + Context → `OBS-*` kuralları | **Yeni** |
| **Cluster composer** | Observation listesi → `CLUSTER-*` grupları (opsiyonel v0.1) | **Yeni (minimal)** |
| **Explainability builder** | Evidence chain paketleme | **Yeni** |
| **Orchestrator** | Akış sırası, hata güvenliği, boş paket | **Yeni** |

---

## 2. Veri Kaynakları (Mevcut Read-Only Katmanlar)

| Kaynak | Mevcut dosya / fonksiyon | Engine'de kullanım |
|--------|--------------------------|-------------------|
| **Trust Metrics** | `trust_intelligence_lib.php` → `zinesh_trust_intelligence_metrics($roomId)` | Metric provider; signal girdisi |
| **AI Context** | `ai_context_lib.php` → `zinesh_escrow_room_ai_context($roomId)` | `behavior_stats`, contract versions, timeline_summary |
| **Room snapshot** | `ai_context_lib.php` → `zinesh_ai_context_room_snapshot($roomId)` | Recovery tetiklemeden salt okuma; context boyutları |
| **Domain Events** | `zinesh_domain_events_lib.php` → `zinesh_domain_events_for_room($roomId)` | Event evidence, replay, signal kanıtı |
| **Timeline** | `escrow_memory_lib.php` → `zinesh_escrow_room_timeline($roomId)` | Explainability UI/Copilot girdisi; event doğrulama |
| **Actor Trust** | `actor_trust_lib.php` → `zinesh_actor_trust_aggregate($actorId)` | `OBS-HIS-*`, `OBS-HIS-004` actor geçmişi |

### Okuma kuralları

1. **`zinesh_escrow_room_find()` kullanılmaz** — recovery tetikler (`escrow_room_lib.php`). AI context snapshot kullanılır (`TRUST_INTELLIGENCE_ARCHITECTURE.md` ile uyumlu).
2. **Yazma yok** — hiçbir `zinesh_json_write`, `zinesh_update_user`, escrow mutator çağrılmaz.
3. **Hata güvenliği** — `try/catch` + boş observation paketi (mevcut `zinesh_trust_intelligence_metrics` pattern'i).

---

## 3. Yeni Dosyalar ve Sorumlulukları

| Dosya | Sorumluluk | Neden ayrı dosya |
|-------|------------|------------------|
| `api/observation_engine_lib.php` | Orchestrator: `zinesh_observation_engine_for_room()`, `zinesh_observation_engine_for_actor()` (opsiyonel v0.1) | Tek giriş noktası; test ve gelecek API için |
| `api/trust_signal_emitter_lib.php` | Metric + events → `SIG-*` listesi; catalog eşikleri | Signal katmanı catalog'dan ayrı test edilir |
| `api/context_resolver_lib.php` | Room snapshot + AI context → context vektörü (`job_type`, `CTX-*` kategoriler) | `CONTEXT_TAXONOMY.md` deterministik atama |
| `api/observation_catalog_lib.php` | `OBS-*` kural tanımları (eşik, gerekli signal'lar, context_required) | Model belgesi → kod eşlemesi tek yerde |
| `api/observation_explain_lib.php` | Evidence chain, confidence hesabı, cluster birleştirme | Explainability/replay testleri izole |
| `api/scripts/e2e-observation-engine-test.php` | Sim data ile determinism, OBS örnekleri, FP senaryoları | Regression |

### v0.1'de eklenmeyecek (bilinçli)

| Öğe | Erteleme gerekçesi |
|-----|-------------------|
| `observation.php` API endpoint | S3.1B veya S3.2; önce lib + e2e doğrulama |
| Observation cache / persistence | Read-only compute-on-demand; replay ile yeterli |
| LLM / Copilot bağlantısı | S4 sprint |
| `escrow_room_lib.php` değişikliği | Stable Core |
| Yeni domain event emit | Engine scope dışı; mevcut event seti ile başla |

### Mevcut dosyalarda değişiklik (v0.1)

**Yok.** Mevcut trust lib'ler **require** edilir; fork veya patch yapılmaz.

---

## 4. Observation Üretim Akışı

```
1. INPUT: room_id (zorunlu), actor_id (opsiyonel — participant scope)

2. EVIDENCE READ (paralel değil — sıralı, deterministik sıra)
   a. events  = zinesh_domain_events_for_room(room_id)
   b. context = zinesh_escrow_room_ai_context(room_id)
   c. room    = zinesh_ai_context_room_snapshot(room_id)
   d. metrics = zinesh_trust_intelligence_metrics(room_id)
   e. timeline = zinesh_escrow_room_timeline(room_id)  // explainability only

3. METRIC (mevcut — adım 2d çıktısı)
   negotiation, settlement, behavior, time blokları

4. SIGNAL EMIT
   trust_signal_emitter_lib:
   - Her SIG-* için catalog eşiği değerlendir
   - Çıktı: signals[] { signal_id, observation_text, metrics_ref, events_ref, emitted: bool }

5. CONTEXT RESOLVE
   context_resolver_lib:
   - room title/description/category alanları (mevcutsa)
   - structured_terms_json alanları
   - Varsayılan: unknown boyutlar
   - Çıktı: context { dimensions{}, categories[], taxonomy_version }

6. OBSERVATION EVALUATE
   observation_catalog_lib kuralları:
   - Gerekli signal'lar + context kuralları → OBS-* fired: bool
   - Çıktı: observations[] { observation_id, statement, confidence, basis }

7. CLUSTER COMPOSE (v0.1 minimal)
   - Önceden tanımlı 4 cluster (`RISK_OBSERVATION_MODEL.md` §5)
   - Üye observation'ların hepsi fired ise cluster listede

8. EXPLAINABILITY BUILD
   observation_explain_lib:
   - Her observation için tam chain: observation → signals → metrics → events
   - model_version, catalog_version, taxonomy_version, generated_at

9. OUTPUT: observation package (array)
```

**Yasak atlamalar:** Event→Observation, Metric→Observation (signal atlanması), Context→Observation (signal atlanması).

---

## 5. Observation Veri Modeli

Kavramsal PHP array şeması (persist edilmez; response/e2e assert için).

### Üst paket

| Alan | Tip | Açıklama |
|------|-----|----------|
| `engine_version` | string | `"0.1"` |
| `model_version` | string | `RISK_OBSERVATION_MODEL` semver (`"1.0"`) |
| `catalog_version` | string | Signal catalog semver |
| `taxonomy_version` | string | Context taxonomy semver |
| `room_id` | string | |
| `actor_id` | string\|null | Opsiyonel scope |
| `observations` | list | Aşağıdaki observation objeleri |
| `clusters` | list | Cluster objeleri |
| `signals_emitted` | list | Tüm emit edilen signal'lar (debug/explain) |
| `context` | object | Tam context vektörü |
| `generated_at` | ISO8601 | |

### Observation objesi

| Alan | Tip | Zorunlu | Açıklama |
|------|-----|---------|----------|
| `observation_id` | string | evet | `OBS-*` |
| `statement` | string | evet | Nötr gözlem cümlesi (TR) |
| `confidence` | enum | evet | `low` \| `medium` \| `high` |
| `confidence_reason` | string | evet | Örn. "n=1 transaction", "3 unknown context dimensions" |
| `basis.signals` | list | evet | `{ signal_id, observation_text, metrics, event_types[] }` |
| `basis.metrics` | object | evet | İlgili metric snapshot (subset) |
| `basis.events` | list | evet | `{ event_type, created_at, count? }` — PII yok |
| `basis.context` | object | evet | Boyutlar + kategoriler |
| `basis.assumptions` | list | hayır | Ontology/catalog assumption ref |
| `fired` | bool | evet | Kural eşleşti mi |

### Evidence Chain (iç içe)

```
observation.basis
  ├── signals[].signal_id
  │     └── metrics_ref → metrics.negotiation.counter_offer_count
  │     └── events_ref → [{ event_type: "counter_offer_created", ... }]
  ├── context.dimensions.job_type
  └── context.categories[] → CTX-SW
```

### Confidence kuralları (v0.1)

| Koşul | Confidence |
|-------|------------|
| `OBS-CTX-001` (≥3 unknown boyut) | `low` |
| Actor `transactions` < 3 | `low` |
| Event zinciri < 2 kritik event | `low` |
| Tam context + zengin event | `high` |
| Aksi | `medium` |

Confidence **gözlem güvenilirliği**dir; karar doğruluğu değil (`TRUST_SIGNAL_CATALOG.md`).

### Signal listesi (paket içi)

`signals_emitted[]`: tüm değerlendirilen signal'lar; `emitted: true/false` — false olanlar explainability için isteğe bağlı dahil (v0.1: yalnızca `true` olanlar observation basis'e girer).

---

## 6. Explainability Koruma Stratejisi

| Mekanizma | Uygulama |
|-----------|----------|
| **Zorunlu basis** | Observation `fired=true` ise `basis.signals`, `basis.events` boş olamaz |
| **Signal catalog referansı** | Her signal `signal_id` + catalog versiyonu |
| **Timeline cross-check** | E2e test: timeline event sayısı ≥ basis.events count |
| **İnsan okunur statement** | Template tabanlı string interpolation (LLM yok) |
| **PII sızdırmama** | `ai_context_lib` sanitize kuralları; uid/email observation paketine girmez |
| **Denetim alanı** | `explain` alt anahtar opsiyonel: ham metric snapshot (public response'ta kırpılabilir — gelecek API) |

Explainability builder (`observation_explain_lib.php`) tek sorumlu; orchestrator inline string üretmez.

---

## 7. Replayability Koruma Stratejisi

| Mekanizma | Uygulama |
|-----------|----------|
| **Pure functions** | Signal/observation kuralları yan etkisiz; global state yok |
| **Sim data test** | `ZINESH_SIM_DATA_DIR` ile sabit event fixture |
| **Version pinning** | Pakette `model_version`, `catalog_version`, `taxonomy_version` |
| **Deterministic time** | Süre metrikleri event timestamp'lerinden; `date()` yalnızca `generated_at` |
| **Replay helper (v0.1 test)** | Aynı fixture iki kez çalıştır → `observations` deep-equal assert |
| **Gelecek (v0.2)** | Event log replay CLI — v0.1'de e2e fixture yeterli |

---

## 8. Deterministic Yapı Garantisi

| Garanti | Nasıl |
|---------|-------|
| **Sabit değerlendirme sırası** | events → metrics → signals → context → observations → clusters |
| **Sabit kural sırası** | `observation_catalog_lib` içinde OBS-ID alfabetik veya öncelik dizisi (dokümante) |
| **Eşikler kod sabiti** | Magic number yok; `observation_catalog_lib` named constants |
| **LLM yok** | Engine içinde OpenAI/LLM çağrısı kesinlikle yok |
| **Random yok** | `random_bytes`, shuffle kullanılmaz |
| **Float karşılaştırma** | Mevcut pattern: `round(..., 2)`, `1e-9` tolerans (`wallet_lib` / `trust_intelligence` ile uyumlu) |
| **Bağımlılık sırası** | `require_once` DAG döngüsüz: emitter → catalog → explain → engine |

**Determinism testi:** e2e'de aynı `room_id` fixture → iki çağrı → `json_encode` identical (generated_at hariç veya sabitlenmiş).

---

## 9. Stable Core Neden Etkilenmez

| İzolasyon | Kanıt |
|-----------|-------|
| **Dosya sınırı** | Yeni dosyalar yalnızca `api/*_lib.php`; `escrow_room_lib.php`, `wallet_lib.php` require edilmez (veya yalnızca read-only loader: `zinesh_escrow_rooms_load` — tercih: **ai_context snapshot only**, rooms load yok) |
| **Recovery tetikleme yok** | `zinesh_escrow_room_find` çağrılmaz |
| **Yazma yok** | Engine'de `zinesh_json_atomic`, `zinesh_update_user` yok |
| **Engine geri beslemesi yok** | Observation çıktısı escrow state machine'e bağlanmaz |
| **Mevcut API davranışı** | `trust_metrics.php`, `actor_trust.php`, `ai_context.php` değişmez |
| **Deploy riski** | Yeni lib'ler deploy edilmezse site çalışır; opt-in require |

Actor Trust agregasyonu `escrow_rooms_load` kullanır — v0.1 room-scoped observation **actor_trust_lib'i opsiyonel** tutar; room observations actor trust olmadan çalışır. Actor-scoped OBS (`OBS-HIS-*`) için `zinesh_actor_trust_aggregate` read-only çağrısı kabul edilebilir (rooms load, mutasyon yok).

---

## 10. Trade-off Analizi

### Seçilen mimari: Katmanlı read-only lib + catalog-driven rules

**Neden bu mimari?**

1. **Mevcut yatırım** — `trust_intelligence_lib.php` v0.1 metric'leri zaten var; duplicate metric hesabı yok.
2. **Belge-kod hizası** — Signal catalog ve observation model ayrı PHP lib'ler; doküman amend → catalog lib güncelleme.
3. **Test edilebilirlik** — Signal emitter ve observation evaluator ayrı unit/e2e test.
4. **Stable Core** — Tek yönlü bağımlılık (yeni → eski).
5. **S3 manifesto** — Deterministic, explainable, no black box.

### Değerlendirilen alternatifler

| Alternatif | Neden seçilmedi |
|------------|-----------------|
| **Observation kurallarını `trust_intelligence_lib.php` içine gömmek** | Katman karışımı; metric≠observation; S2 mimari ihlali |
| **LLM ile observation üretmek** | Non-deterministic; manifesto ihlali; explainability zayıf |
| **Observation'ları JSON dosyada cache'lemek** | Write surface; stale data; v0.1 gereksiz karmaşıklık |
| **Tek monolitik `observation_lib.php`** | Test ve catalog amend zor; 5 dosya sınırlı ve net |
| **Escrow claim sırasında observation yazmak** | Stable Core + write; kesin red |
| **Risk skoru üretmek** | `RISK_OBSERVATION_MODEL` — observation ≠ score; S3 scope |
| **Yeni API endpoint ile başlamak** | Lib doğrulanmadan HTTP yüzeyi riski; e2e önce |
| **SQL/DB observation store** | Kullanıcı kuralı: no database; flat-file platform uyumu |

### Bilinen sınırlamalar (v0.1 kabul)

| Sınır | Etki |
|-------|------|
| Context resolver heuristic | `title`/`description` parse — eksikse `unknown` |
| Actor trust rooms scan | Büyük kullanıcıda performans — v0.1 kabul; cache sonraki sprint |
| Cluster minimal | 4 sabit küme; dinamik cluster yok |
| Türkçe statement template | i18n sonra |

---

## 11. Test Stratejisi

### Test dosyası

`api/scripts/e2e-observation-engine-test.php` — pattern: `e2e-trust-intelligence-test.php`, `e2e-inv-a4-settlement-recovery-test.php`.

### Test ortamı

- `ZINESH_SIM_DATA_DIR` izole temp
- `users.json`, `escrow_rooms.json`, domain events, contract versions fixture
- Production data dokunulmaz

### Doğrulanacak observation'lar (v0.1 minimum)

| OBS-ID | Fixture senaryosu |
|--------|-------------------|
| OBS-REV-001 | Çok `counter_offer` + `terms_updated` |
| OBS-NEG-001 | Yüksek negotiation_rounds |
| OBS-HIS-002 | İlk işlem actor |
| OBS-CTX-001 | Boş context boyutları |
| OBS-SET-001 | `settlement_failed` + `escrow_recovered` |
| OBS-CTR-002 | Eksik structured terms |
| OBS-EVD-001 | Seyrek event timeline |

Negatif assert: engine hiçbir senaryoda `fired` observation için boş `basis.events` üretmemeli.

### Determinism testi

- Aynı fixture → iki `zinesh_observation_engine_for_room()` → observations dizisi eşit (`generated_at` normalize).

### Regression koruma

| Paket | Ne zaman çalışır |
|-------|------------------|
| `e2e-observation-engine-test.php` | Her observation engine değişikliği |
| `e2e-trust-intelligence-test.php` | Metric provider değişmedi doğrulama |
| `e2e-trust-metrics-test.php` | API regression (engine API'ye dokunmaz) |
| `e2e-ai-context-test.php` | Context reader regression |
| `e2e-actor-trust-test.php` | Actor-scoped OBS varsa |
| `e2e-escrow-memory-test.php` | Event emit regression (dolaylı) |

CI/local: `php api/scripts/e2e-observation-engine-test.php` exit 0 zorunlu.

### Stable Core regression

- `e2e-inv-a4-settlement-recovery-test.php`
- `e2e-inv-s1-1-employer-settlement-guard-test.php`

Engine sprint'inde bu testler **değişmemeli** (escrow patch yok).

---

## Implementasyon Sprint Sırası (Onay Sonrası — S3.1B öneri)

| Adım | İş | Çıktı |
|------|-----|-------|
| 1 | `trust_signal_emitter_lib.php` + unit/e2e signal assert | SIG-* emit |
| 2 | `context_resolver_lib.php` + context fixture test | Context vektör |
| 3 | `observation_catalog_lib.php` | OBS-* kuralları |
| 4 | `observation_explain_lib.php` | basis builder |
| 5 | `observation_engine_lib.php` | orchestrator |
| 6 | `e2e-observation-engine-test.php` | full regression |
| 7 | README cross-link (`docs/` only) | — |

**Tahmini kapsam:** ~6 yeni PHP dosyası, 0 mevcut production dosya değişikliği, 1 e2e script.

---

## Onay Kriterleri (Plan)

- [ ] Stable Core dokunulmazlık kabul
- [ ] Evidence Before Intelligence zinciri korunur
- [ ] v0.1 scope: lib + e2e, API yok
- [ ] 15 OBS tanımının v0.1'de hangi alt kümesi (yukarıdaki minimum) yeterli
- [ ] Actor-scoped observation v0.1'de dahil mi (öneri: room-only MVP, actor OBS adım 2)

---

## Eklenecek Dosyalar — Özet Listesi

| # | Dosya | Neden |
|---|-------|-------|
| 1 | `api/trust_signal_emitter_lib.php` | Metric→Signal katmanı; catalog eşikleri; determinism |
| 2 | `api/context_resolver_lib.php` | CONTEXT_TAXONOMY deterministik atama |
| 3 | `api/observation_catalog_lib.php` | OBS-* kural tanımları (model belgesi kod eşlemesi) |
| 4 | `api/observation_explain_lib.php` | Evidence chain + confidence; black box önleme |
| 5 | `api/observation_engine_lib.php` | Orchestrator; tek public entry |
| 6 | `api/scripts/e2e-observation-engine-test.php` | Regression + determinism + OBS doğrulama |

**Değiştirilmeyecek:** `escrow_room_lib.php`, `wallet_lib.php`, `trust_intelligence_lib.php` (v0.1), mevcut API endpoint'leri.

**Gelecek sprint (plan dışı):** `api/observation.php` + `observation_endpoint_lib.php` — HTTP yüzeyi lib onayından sonra.

---

## Belge Onayı

| Rol | Ad | Tarih | Onay |
|-----|-----|-------|------|
| Engineering | | | |
| Product | | | |
| Architect | | | |

---

*Bu plan onaylandıktan sonra S3.1B implementasyon sprint'i açılır. Plan, referans mimari belgelerle çelişemez; çelişki durumunda üst belge (Ontology / Risk Model) önceliklidir.*
