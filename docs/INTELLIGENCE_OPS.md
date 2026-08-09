# Intelligence Operations v1.0 (S7.0)

Production hardening katmanı. Runtime davranışını değiştirmez; yalnızca regression orchestration, CI readiness ve read-only telemetry sağlar.

## Amaç

**Tek komut → Tek rapor → Deploy kararı**

```bash
php api/scripts/run-intelligence-regression.php
# veya
npm run test:intelligence-regression
```

Çıktı: `intelligence-regression.json` (repo kökü)

Rapor türü: **`intelligence_regression_slice`** — S7 orchestrator paketleri. Tam trust intelligence stack değildir; `scope.not_executed` alanında listelenmeyen e2e paketleri bu raporun dışındadır.

## Terminoloji (Architecture Validation vs Regression Slice)

| Kavram | Ne doğrular | Ne çalıştırmaz |
|--------|-------------|------------------|
| **Architecture Validation** (`architecture_validation_lib.php`) | Mimari uyum, frozen terminology, Stable Core izolasyonu, read-only / human-in-control | `regression_packages` altındaki e2e script'lerini **çalıştırmaz**; yalnızca dosya varlığını doğrular |
| **Intelligence Regression Slice** (`run-intelligence-regression.php`) | S7 section listesi (aşağıda); deploy gate kanıtı | Trust Metrics / Signal Runtime / Context Resolver vb. ayrı e2e paketlerini **çalıştırmaz** |

`Architecture Validation PASS` ≠ tüm e2e paketleri koştu.  
`Intelligence Regression Slice PASS` ≠ tam intelligence stack doğrulandı.

## Deploy Gate (S7.0.1 + S7.1)

Tüm production deploy girişleri `require_intelligence_regression_pass()` kullanır:

- `scripts/deploy_live.py`
- `scripts/deploy_api_only.py`
- `scripts/deploy_app_subdomain.py`
- `scripts/deploy_api_cors.py`
- `scripts/deploy_actor_trust.py`
- `npm run check:regression-gate` (`deploy:firebase:app` öncesi; Python deploy script'leri gate'i doğrudan çağırır)

Gate şunları doğrular:

- `overall == PASS`
- `report_kind == intelligence_regression_slice`
- `report_fingerprint` (64-char sha256)
- `generated_at` tazeliği (varsayılan 24 saat; `ZINESH_REGRESSION_MAX_AGE_HOURS`)
- Tüm zorunlu section'lar `PASS` (frontend `SKIP` deploy'a izin vermez)
- Git repo varsa: `source_git_head` == `git rev-parse HEAD`

Acil durum bypass: `ZINESH_SKIP_REGRESSION_GATE=1` (log'a WARN yazar).

## Operational Reliability (S7.2)

Orchestrator hata anlarında:

- Her section try/catch ile izole edilir; tek section orchestrator'u crash etmez
- Subprocess timeout: `ZINESH_REGRESSION_SUBPROCESS_TIMEOUT_SECONDS` (varsayılan 900s)
- `exit_code` / stdout summary tutarsızlığı `anomalies` olarak raporlanır ve FAIL üretir
- Rapor `finally` bloğunda yazılır; artifact yazılamazsa exit code 1
- `reliability.issues` boş değilse deploy gate reddeder
- Deploy gate `report_fingerprint` içeriğini yeniden hesaplayarak doğrular

## Governance Authority Matrix

| Authority | Sorumluluk | Kaynak | Birbirinin yerine geçer mi? |
|-----------|------------|--------|----------------------------|
| **Architecture Validation** | Architecture Freeze, Development Protocol, Trust Domain Model uyumu; Stable Core izolasyonu; frozen terminology; read-only / human-in-control denetimi | `api/architecture_validation_lib.php`, `api/scripts/e2e-architecture-validation-test.php` | Hayır |
| **Copilot Evaluation** | Copilot kalite regresyonu; validator / explainability / human-control / prompt regression; dataset coverage | `api/copilot_evaluation_lib.php`, `api/copilot_evaluation_runner.php`, `docs/copilot_eval/*.json` | Hayır |
| **Regression Orchestrator** | Slice section'larını çalıştırır; unified rapor üretir; deploy gate kanıtı | `api/scripts/run-intelligence-regression.php` | Hayır (executor; otorite değil) |

### Kurallar

1. Architecture Validation **mimari uyum** otoritesidir; Copilot Evaluation **ürün kalitesi regresyonu** otoritesidir.
2. Architecture Validation PASS, Copilot Evaluation FAIL olsa bile deploy kararı Copilot Evaluation'a göre verilir.
3. Copilot Evaluation PASS, Architecture Validation FAIL olsa bile deploy kararı Architecture Validation'a göre verilir.
4. Unified regression raporu her iki otoritenin **ayrı section sonuçlarını** taşır; tek otorite diğerini override etmez.

## Regression Pipeline (slice)

Bu orchestrator yalnızca aşağıdaki section'ları **çalıştırır**:

| Section | Script |
|---------|--------|
| Architecture Validation | `api/scripts/e2e-architecture-validation-test.php` |
| Risk Engine | `api/scripts/e2e-risk-engine-test.php` |
| Risk Engine Backend | `api/scripts/e2e-risk-engine-backend-test.php` |
| Copilot Framework | `api/scripts/e2e-copilot-framework-test.php` |
| OpenAI Provider (Stub) | `api/scripts/e2e-openai-provider-test.php` |
| Copilot Evaluation | `api/scripts/e2e-copilot-evaluation-test.php` |
| Frontend Contracts | `npm run test:intelligence-ops-frontend` |

Orchestrator: `api/scripts/run-intelligence-regression.php`

## CI Readiness

- **Tek giriş:** `php api/scripts/run-intelligence-regression.php`
- **Exit code:** `0` = PASS, `1` = FAIL
- **Artifact:** `intelligence-regression.json` (upload zorunlu)
- **Workflow:** `.github/workflows/intelligence-regression.yml`
- **Doğrulama:** CI job `overall == PASS` ve `report_kind` kontrolü yapar
- **Concurrency:** Aynı branch'te paralel regression run iptal edilir

## Operational Telemetry (Read-Only)

Unified raporda `telemetry` bloğu yalnızca metadata taşır:

- `execution_ms` (gözlem amaçlı)
- `provider`, `prompt_version`, `dataset_version`, `dataset_fingerprint`, `report_fingerprint`
- OpenAI smoke: `provider_resolved`, `config_loaded`, `validator_path`
- CI: `run_id`, `workflow` (GitHub Actions ortamında)

Telemetry runtime davranışını değiştirmez. API key veya LLM yanıtı artifact'a yazılmaz.

## Determinism

`report_fingerprint` hesaplanırken `execution_ms`, `duration_ms`, `generated_at`, `source_started_at`, `source_git_head`, `diagnostics` ve benzeri zaman/ortam alanları **dahil edilmez**.

## Scope (S7.0)

**İzin verilen:** orchestrator, unified report, CI, read-only telemetry, OpenAI smoke readiness, governance netleştirmesi.

**Yasak:** Stable Core, Risk Engine runtime, Copilot framework katmanları, yeni endpoint, frontend değişikliği, yeni intelligence.

## Referanslar

- `docs/ARCHITECTURE_FREEZE_v1.md`
- `docs/DEVELOPMENT_PROTOCOL.md`
- `docs/TRUST_DOMAIN_MODEL.md`
