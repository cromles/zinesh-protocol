# Release S1 — Escrow Settlement Hardening

**Release:** S1 + S1.1  
**Tarih:** 2026-08-07  
**Kapsam:** `api/escrow_room_lib.php` (tek production dosyası)  
**Referans:** `docs/INVARIANTS.md`  
**Durum:** Deploy'a hazır (doküman onayı bekleniyor)

---

## 1. Sprint Özeti

### Çözülen problemler

| Problem | Açıklama |
|---------|----------|
| **Split-brain settlement (crash window)** | `finalize_success` içinde wallet `users.json`'a commit edildikten sonra process crash olursa `settlingWalletApplied` flag'i yazılmadan kalınıyordu. TTL recovery yalnızca flag'e bakarak `settling_revert` yapıyor; oda `locked`'a dönerken wallet commit kalıyordu. Yeniden finalize → **çift ödeme riski**. |
| **Recovery kanıtı yetersizdi** | Recovery, gerçek wallet commit kanıtı yerine yalnızca `settlingWalletApplied` flag'ine güveniyordu. |
| **Paralel settling (concurrency)** | Aynı employer için birden fazla oda eşzamanlı `settling` olabiliyordu. INV-A4 patch'indeki `zinesh_escrow_room_settlement_wallet_committed()` employer **toplam bakiyesine** baktığı için başka odanın debit'i yanlış commit kanıtı sayılabiliyordu (false-complete riski). S1.1 employer guard ile kapatıldı. |

### Kapatılan invariantlar

| ID | Sprint | Durum |
|----|--------|-------|
| **INV-A4** | S1 | **RESOLVED** — wallet commit sonrası TTL recovery odayı geri almaz |
| **INV-C2** (wallet commit + flag yok senaryosu) | S1 | **RESOLVED** (A4 ile birlikte) — commit kanıtı varsa recovery `settling_complete` yapar |

### S1.1 ek güvenlik (invariant değil)

| Öğe | Durum |
|-----|-------|
| **Employer Settlement Guard** | **IMPLEMENTED** — aynı employer için eşzamanlı yalnızca bir `settling` oda |

### Dokunulmayan alanlar (bilinçli)

- Wallet lib (`wallet_lib.php`) — settlement mutasyonu değişmedi
- Treasury (`zinesh_distribute_commission` / `zinesh_add_treasury_fee`)
- Recovery wallet/treasury yazımı (INV-C3 korundu)
- API endpoint'leri, trust katmanı, AI context, memory emitters
- INV-A5, INV-A8, INV-D3, INV-E6 ve diğer sprint haritası maddeleri

---

## 2. Çözülen Invariantlar

### INV-A4 — `finalize_success` wallet commit sonrası TTL recovery geri almamalı

**Status:** RESOLVED

**Önceki ihlal zinciri** (`docs/INVARIANTS.md` §5.2):

1. `zinesh_wallet_apply_tl_escrow_settlement()` → `users.json` commit  
2. Crash / exception → `settlingWalletApplied` yazılmadı  
3. TTL recovery → `settling_revert` → oda `locked` / `completion_pending`  
4. Wallet commit kalır → retry'de çift ödeme riski  

**S1 çözümü** (`api/escrow_room_lib.php`):

- `settling` claim anında employer bakiye snapshot'ı (`settlingSnapshotEmployerEscrow`, `settlingSnapshotEmployerUsdt`, `settlingSnapshotLockTry`)
- `zinesh_escrow_room_settlement_wallet_committed()` — snapshot vs güncel `users.json` delta kontrolü
- Recovery: flag **veya** commit kanıtı → `settling_complete` (revert yok)
- `settlingWalletApplied` wallet commit'ten hemen sonra yazılır (crash penceresi daraltıldı)

**Regression:** `api/scripts/e2e-inv-a4-settlement-recovery-test.php` (12 assertion, PASS)

---

### INV-C2 — Flag yokken TTL recovery revert (A4 ile çakışan davranış)

**Status:** RESOLVED (S1 kapsamında, A4 ile birlikte)

Wallet gerçekten commit edilmişse artık `settling_revert` değil `settling_complete` çalışır. Wallet commit yoksa `settling_revert` davranışı korunur (regression test senaryo 2).

---

### S1.1 — Employer Settlement Guard

**Status:** IMPLEMENTED

**Kural:** Aynı `employerUid` için başka bir oda `status='settling'` iken `finalize_success` claim reddedilir.

**Konum:** `zinesh_escrow_room_finalize_success()` — `escrow_rooms.json` atomic claim callback içi.

**Regression:** `api/scripts/e2e-inv-s1-1-employer-settlement-guard-test.php` (8 assertion, PASS)

**Not:** Bu bir invariant kaydı değildir; geçici production safety guard'dır (§3).

---

## 3. Geçici Güvenlik Kuralları

### Employer Settlement Guard

| Alan | Değer |
|------|-------|
| **Tür** | Temporary Production Safety Guard |
| **Kalıcılık** | Kalıcı mimari **değil** |
| **İş kuralı** | Employer başına eşzamanlı en fazla **1** `settling` oda |
| **Gerekçe** | INV-A4 recovery kanıtı employer toplam bakiyesine dayanır; paralel settling false-complete riskini operasyonel olarak kapatır |
| **Kaldırma koşulu** | Room-specific settlement evidence devreye alındığında (Roadmap §4) |

**Ürün etkisi:** Aynı işverenin iki farklı escrow odasını aynı anda finalize etmesi backend tarafından engellenir. İlk oda `completed` olduktan sonra ikinci oda finalize edilebilir.

---

## 4. Roadmap

S1 release sonrası planlanan sprintler (`docs/INVARIANTS.md` §7 ile uyumlu):

| Sprint | Konu | Amaç |
|--------|------|------|
| **S2** | Room-Specific Settlement Evidence | Oda bazlı commit kanıtı (idempotency key / settlement receipt); employer toplam bakiye heuristic'inin kaldırılması |
| **S3** | Treasury Atomicity (**INV-A8**) | Wallet commit ile `treasury_ledger.json` commission yazımının tutarlılığı; crash sonrası reconciliation |
| **S4+** | Employer Guard kaldırılması | Room-specific evidence tamamlandığında paralel settling politikası yeniden değerlendirilir; guard kaldırılır veya iş kuralına dönüştürülür |
| *(mevcut harita)* | INV-D3, INV-A5, INV-A6, INV-E6 | `INVARIANTS.md` sprint haritasına göre sıradaki hardening maddeleri |

---

## 5. Production Checklist

### 5.1 Deploy öncesi

**Önkoşullar**

- [ ] `secrets/deploy.local.env` dolu (`ZINESH_DEPLOY_HOST`, `ZINESH_DEPLOY_PASS`)
- [ ] Git'te S1 + S1.1 değişiklikleri review edildi
- [ ] `docs/INVARIANTS.md` v1.1 güncellemesi (INV-A4 → RESOLVED) — ayrı commit/PR (opsiyonel, release sonrası)

**Syntax**

```bash
php -l api/escrow_room_lib.php
```

**Regression testleri (sunucuda veya izole sim)**

```bash
php api/scripts/e2e-inv-a4-settlement-recovery-test.php
php api/scripts/e2e-inv-s1-1-employer-settlement-guard-test.php
php api/scripts/e2e-escrow-memory-test.php
php api/scripts/e2e-actor-trust-test.php
php api/scripts/e2e-ai-context-test.php
```

**Yerel/CI remote runner (opsiyonel)**

```bash
python scripts/run_s1_escrow_tests_remote.py
```

**Beklenen:** Tüm testler `0 failed`.

**Deploy komutu**

```bash
python scripts/deploy_api_only.py
```

> Not: `deploy_api_only.py` tüm API dosyalarını yükler. Minimum S1 değişikliği yalnızca `api/escrow_room_lib.php` + test scriptleridir. Operasyonel tercih: tam API deploy veya yalnızca `escrow_room_lib.php` SFTP.

**Deploy edilecek dosyalar (minimum)**

| Dosya | Zorunlu |
|-------|---------|
| `api/escrow_room_lib.php` | Evet |
| `api/scripts/e2e-inv-a4-settlement-recovery-test.php` | Önerilir |
| `api/scripts/e2e-inv-s1-1-employer-settlement-guard-test.php` | Önerilir |

---

### 5.2 Deploy sonrası smoke

**Syntax (remote)**

```bash
php -l /www/wwwroot/zinesh.com/api/escrow_room_lib.php
```

**Regression (remote)**

```bash
php /www/wwwroot/zinesh.com/api/scripts/e2e-inv-a4-settlement-recovery-test.php
php /www/wwwroot/zinesh.com/api/scripts/e2e-inv-s1-1-employer-settlement-guard-test.php
```

**API probe** (`deploy_api_only.py` içindeki ile aynı)

```bash
curl -s -o /dev/null -w 'wallet=%{http_code}\n' \
  -X POST https://www.zinesh.com/api/wallet.php \
  -H 'Content-Type: application/json' \
  -d '{"action":"escrow_jobs_list","sessionToken":"x"}'
```

Beklenen: `wallet=200` (veya auth'a göre beklenen kod; endpoint ayakta).

**Wallet invariant audit (opsiyonel)**

```bash
php /www/wwwroot/zinesh.com/scripts/verify_wallet_invariants.php
```

**Manuel fonksiyonel smoke**

- [ ] Tek oda finalize → `completed`, worker bakiyesi artar
- [ ] Aynı oda tekrar finalize → idempotent (`İş zaten tamamlanmış`)
- [ ] (İki oda aynı employer) İlk oda settling iken ikinci finalize → reddedilir; ilk oda tamamlanınca ikinci çalışır

---

### 5.3 Rollback planı

| Adım | Aksiyon |
|------|---------|
| 1 | Önceki `escrow_room_lib.php` yedeğini sunucuya geri yükle |
| 2 | `php -l api/escrow_room_lib.php` |
| 3 | Smoke: tek oda finalize akışı |
| 4 | **Dikkat:** Rollback INV-A4 düzeltmesini geri alır; crash-window split-brain riski yeniden açılır |
| 5 | Rollback S1.1 guard'ı da geri alır; paralel settling yeniden mümkün |

**Rollback dosyası:** Yalnızca `api/escrow_room_lib.php` (S1/S1.1 tek dosya release).

**Veri migrasyonu:** Gerekmez. Yeni oda alanları (`settlingSnapshot*`) opsiyonel; eski odalar etkilenmez.

**In-flight `settling` odalar:** Snapshot'sız eski `settling` odalar rollback sonrası önceki recovery davranışına döner.

---

## 6. Risk Acceptance

### Production'da kapatılan riskler

| Risk | Önce | Sonra |
|------|------|-------|
| Wallet commit + crash → TTL `settling_revert` → çift ödeme | **Açık (INV-A4 ihlali)** | **Kapalı** — commit kanıtı → `settling_complete` |
| Recovery sonrası wallet tekrar debit | Teorik (retry) | **Kapalı** — recovery wallet çağırmaz; completed guard |
| Aynı employer paralel `settling` → false-complete (evidence helper) | **Açık (S1 patch sonrası keşfedildi)** | **Kapalı (S1.1)** — employer guard |

### Production'da bilinçli olarak kabul edilen riskler

| Risk | Seviye | Gerekçe | Sonraki adım |
|------|--------|---------|--------------|
| **INV-A8** — wallet commit sonrası treasury yazılmazsa gap kalır; recovery treasury tamamlamaz | Orta (muhasebe) | S1 kapsamı dışı; bilinen ihlal | Sprint S3 (Treasury Atomicity) |
| **Employer guard** — aynı employer eşzamanlı iki iş finalize edemez | Düşük (ürün) | Geçici iş kuralı; çoğu kullanımda kabul edilebilir | Room-specific evidence sonrası guard kaldırma/değerlendirme |
| **Snapshot'sız in-flight `settling` odalar** (deploy anında) | Düşük | Deploy öncesi claim edilmiş snapshot'sız odalar kanıt helper'ında fallback'e bağlı; flag veya revert | Operasyonel: deploy düşük trafikte; TTL sonrası recovery doğrulanır |
| **INV-D3, INV-A5, INV-E6** vb. | Değişmedi | S1 dokunmadı | İlgili sprintler |

### Risk kabul imzası

| Rol | Ad | Tarih | Onay |
|-----|-----|-------|------|
| Engineering | | | |
| Product | | | |

---

## Ek: Test özeti (release doğrulama anı)

| Test | Sonuç |
|------|-------|
| `e2e-inv-a4-settlement-recovery-test.php` | 12/12 PASS |
| `e2e-inv-s1-1-employer-settlement-guard-test.php` | 8/8 PASS |
| `e2e-escrow-memory-test.php` | 18/18 PASS |
| `e2e-actor-trust-test.php` | 10/10 PASS |
| `e2e-ai-context-test.php` | 13/13 PASS |
| `e2e-actor-trust-api-test.php` | 18/18 PASS |

**Toplam escrow + trust paketi:** 79 assertion, 0 failed (izole `/tmp` + prod API script).

---

## Ek: Değişen production yüzeyi

| Fonksiyon | Değişiklik |
|-----------|------------|
| `zinesh_escrow_room_settlement_wallet_committed()` | **Yeni** — commit kanıtı helper |
| `zinesh_escrow_room_recover_stale_transient_if_needed()` | Evidence tabanlı `settling_complete` |
| `zinesh_escrow_room_finalize_success()` | Snapshot at claim; flag erken; employer guard (S1.1) |

API response şekli ve endpoint imzaları **değişmedi**.

---

*Bu belge S1 + S1.1 production release notudur. Deploy işlemi bu belgenin onayından sonra `scripts/deploy_api_only.py` ile yapılır.*
