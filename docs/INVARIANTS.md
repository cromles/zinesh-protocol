# Zinesh Escrow Core — Invariant Specification

**Belge sürümü:** 1.0  
**Tarih:** 2026-08-07  
**Durum:** Production referansı (onay bekliyor)  
**Kapsam:** `api/escrow_room_lib.php`, `api/wallet_lib.php`, `api/escrow_memory_lib.php`

**Kapsam dışı:** `escrow_jobs_lib.php` ve `admin.php` bu belgenin kapsamı dışındadır; yalnızca çağrı zinciri referansı için okunabilir.

**Kural:** Bu belge yalnızca mevcut kodun doğrulanmış davranışını tanımlar. Implementasyon önerisi içermez.

---

## 0. Terminoloji

| Terim | Anlam |
|-------|-------|
| **Wallet commit** | `users.json` üzerinde `zinesh_json_atomic` ile kalıcı yazılmış bakiye değişikliği |
| **Room commit** | `escrow_rooms.json` üzerinde `zinesh_json_atomic` ile kalıcı yazılmış durum değişikliği |
| **Transient state** | `locking` veya `settling` — `ZINESH_ESCROW_TRANSIENT_TTL_SEC` (600s) sonra recovery tetiklenebilir |
| **Rollback bypass** | `zinesh_json_response()` → `exit` (`_bootstrap.php` L189); `try/catch` ve closure rollback atlanır |

### Kalıcı dosyalar (escrow core)

| Dosya | Sabit / fonksiyon |
|-------|-------------------|
| `escrow_rooms.json` | `ZINESH_ESCROW_ROOMS_FILE` |
| `users.json` | `zinesh_update_user`, `zinesh_wallet_apply_tl_escrow_settlement` |
| `treasury_ledger.json` | `zinesh_add_treasury_fee`, `zinesh_distribute_commission` |
| `zinesh_events.json` | `escrow_memory_lib.php` → domain events |
| `audit_log.json` | `zinesh_audit` |

---

## 1. State Machine — Flow Bazlı

### 1.1 Global oda durumları

| Durum | Terminal? | Transient? |
|-------|-----------|------------|
| `negotiating` | Hayır | Hayır |
| `terms_pending` | Hayır | Hayır |
| `locking` | Hayır | **Evet** (TTL 600s) |
| `locked` | Hayır | Hayır |
| `completion_pending` | Hayır | Hayır |
| `settling` | Hayır | **Evet** (TTL 600s) |
| `completed` | **Evet** | Hayır |
| `disputed` | Hayır | Hayır |
| `resolved` | **Evet** | Hayır |
| `cancelled` | **Evet** | Hayır |

Recovery giriş noktası: `zinesh_escrow_room_find()` her çağrıda `zinesh_escrow_room_recover_stale_transient_if_needed()` tetikler (`escrow_room_lib.php` L254–256).

---

### 1.2 `accept_terms` — `zinesh_escrow_room_accept_terms` (L679–963)

#### State geçişleri

```
terms_pending
    │ Koşul: doğrulama gate OK, doğru rol, status=terms_pending, sözleşme ≥40 karakter, tutar ≥1
    │ Yan etki: yok (henüz)
    ▼
locking                          [TRANSIENT — lockingAt set, L775]
    │ Koşul: zinesh_json_atomic claim başarılı (L766–785)
    │ Wallet: employer escrowBalance += amount (L823–830)
    │         worker escrowBalance += collateralAmount (opsiyonel, L840–847)
    │ Room patch: lockingEmployerFunded, lockingWorkerCollateralFunded (L835, L856–859)
    ▼
locked                           [FINAL — lockedAt set, L887–888]
    │ Koşul: finalize atomic başarılı (L863–901)
    │ Rollback path: L790–818 closure, L903–915 wallet geri alma
    ▼
(terminal değil — sonraki flow'lara açık)
```

**Başarısızlık / geri dönüş yolları:**

| Nokta | Room | Wallet | Recovery |
|-------|------|--------|----------|
| Claim fail (L786–788) | `terms_pending` | Değişmedi | — |
| Employer lock fail + catch (L831–834) | `rollbackRoom()` → `terms_pending` | Değişmedi | — |
| Employer lock `zinesh_json_response` exit (L827) | `locking` kalır | **Commit olmuş olabilir** | TTL `locking_revert` (L71–84, L127) |
| Collateral fail (L848–855) | rollback | employer geri alınır | — |
| Finalize fail (L903–915) | rollback | her iki taraf geri alınır | — |
| TTL `locking` expire (L71–84) | → `terms_pending` | `release_locking_hold` (L127, L33–51) | Otomatik |

**Yan etkiler (başarı sonrası, L918–954):**

- `zinesh_escrow_room_add_message`
- `zinesh_audit('escrow_room_terms_accepted')`
- `zinesh_escrow_memory_on_terms_accepted` → events: `terms_accepted`, `contract_finalized`, `escrow_funded`, `escrow_locked`
- `zinesh_campaign_record_agreement` (her iki taraf)
- `zinesh_log_tl_escrow_lock`
- `zinesh_eslesme_sinyal_*`

**Wallet mutation özeti:**

- Lock aşamasında yalnızca `escrowBalance` artar; `usdtBalance` değişmez (L829, L846).
- Gerçek USDT debit settlement'ta yapılır.

---

### 1.3 `finalize_success` — `zinesh_escrow_room_finalize_success` (L1310–1540)

Tetikleyici: `zinesh_escrow_room_confirm_complete` ikinci onay sonrası (L1278–1281).

#### State geçişleri

```
locked | completion_pending
    │ Koşul: her iki taraf onayladı (confirm_complete) veya doğrudan çağrı
    ▼
settling                         [TRANSIENT — settlingAt, settlingPreviousStatus, L1382–1385]
    │ Koşul: settling claim atomic (L1367–1389)
    │ Memory: zinesh_escrow_memory_on_settlement_started (L1393)
    ▼
(wallet commit — users.json)     [L1413 — zinesh_wallet_apply_tl_escrow_settlement]
    │ employer: escrowBalance -= lockAmount, usdtBalance -= lockAmount
    │ worker: usdtBalance += workerPayout
    │ worker: escrowBalance -= collateralRelease (varsa)
    ▼
settling + settlingWalletApplied=true   [L1439 patch]
    ▼
completed                        [L1441–1458 veya retry L1461–1475]
    │ Memory: zinesh_escrow_memory_on_settlement_completed (L1516)
    │ Audit: settlement_debit_mismatch olası (L1425–1432)
```

**Recovery (`zinesh_escrow_room_recover_stale_transient_if_needed`, L87–114):**

| Koşul | Room sonucu | Wallet |
|-------|-------------|--------|
| `settling` TTL + `settlingWalletApplied` **yok** | → `settlingPreviousStatus` (`locked`/`completion_pending`) L104–112 | Değişmedi (recovery wallet'a dokunmaz) |
| `settling` TTL + `settlingWalletApplied` **var** | → `completed` zorla L92–102 | Değişmedi (zaten commit edilmiş) |

**Catch bloğu (L1478–1497):**

| `walletSettled` | Room davranışı |
|-----------------|----------------|
| `false` | `settling` → `previousStatus` revert (L1480–1492) |
| `true` | **Revert yok** — yalnızca audit `escrow_room_settlement_partial` (L1494) |

---

### 1.4 `file_dispute` — `zinesh_escrow_room_file_dispute` (L1681–1808)

#### State geçişleri

```
locked | completion_pending
    │ Koşul: participant, status uygun, deposit collect OK
    │ Deposit collect (L1712–1716):
    │   employer_locked → wallet hareketi yok, room field düşümü planlanır
    │   worker_balance  → wallet escrowBalance += deposit (L1583–1590)
    │   worker_collateral → wallet hareketi yok, room field düşümü planlanır
    ▼
disputed                         [L1748 — dispute{} nested object]
    │ Memory: zinesh_escrow_memory_on_dispute_opened (L1799)
    │ Audit: escrow_room_dispute_filed (L1793)
```

**Rollback:**

- Room atomic fail (L1776–1778): `zinesh_escrow_dispute_refund_filer_deposit` — yalnızca `worker_balance` source (L1611–1621).

---

### 1.5 `resolve_dispute` — `zinesh_escrow_room_resolve_dispute` (L1816–1974)

Admin-only (`admin.php`). Transient state **yok**.

#### State geçişleri

```
disputed
    │ Koşul: dispute.status != resolved
    │ Wallet commit (users.json atomic, L1869–1906):
    │   employer escrow release, USDT debit (employerDebit), worker credit (workerPayout)
    │   worker escrow release (workerEscrowRelease)
    ▼
(treasury — ayrı dosya, non-atomic)   [L1908–1915]
    ▼
resolved                         [L1931 — terminal]
    │ Memory: zinesh_escrow_memory_on_dispute_resolved (L1958)
    │ Trust: zinesh_apply_trust_fault_penalty (L1852–1856)
```

**Idempotency:** Zaten `resolved` dispute → early return (L1824–1826). Wallet tekrar çalışmaz.

**Rollback:** Wallet commit sonrası room yazımı fail ederse **geri alma yok** (L1917–1947 wallet'tan sonra).

---

### 1.6 `request_cancel` — `zinesh_escrow_room_request_cancel` (L1977–2085)

#### State geçişleri

```
locked | completion_pending
    │ Koşul: participant, status uygun
    ▼
(aynı status + cancel flag)      [tek taraflı — employerCancelRequested veya workerCancelRequested, L2008–2012]
    │ Koşul: karşı taraf henüz onaylamadı
    │ Wallet: yok
    ▼
cancelled                        [karşılıklı onay — L2015–2018]
    │ Wallet unlock (L2054–2068):
    │   employer escrowBalance -= unlockEmployer
    │   worker escrowBalance -= collateral
    │ Memory: YOK
    │ Domain event: YOK
```

**Sıra:** Room `cancelled` commit **önce** (L2017), wallet unlock **sonra** (L2054).

---

## 2. Production İnvariantları (Koddan Türetilmiş)

Aşağıdaki invariantlar mevcut kaynak koda dayanır. "Olması gereken" değil, "kodun iddia ettiği veya ihlal ettiği" ayrımı §3'te verilir.

### A. Wallet ↔ Room tutarlılığı

| ID | İnvariant |
|----|-----------|
| **INV-A1** | `locking` transient süresi dolduğunda, `lockingEmployerFunded`/`lockingWorkerCollateralFunded` varsa ilgili `escrowBalance` azaltılır ve oda `terms_pending`'e döner. |
| **INV-A2** | `accept_terms` başarılı tamamlandığında oda `locked` ve employer `escrowBalance` en az `employerLockedTry` kadar artmış olmalıdır. |
| **INV-A3** | `finalize_success` wallet commit sonrası oda asla `locked`/`completion_pending`'e geri alınmamalıdır (catch bloğu). |
| **INV-A4** | `finalize_success` wallet commit sonrası TTL recovery odayı geri almamalıdır. |
| **INV-A5** | `resolve_dispute` wallet commit sonrası oda mutlaka `resolved` olmalıdır. |
| **INV-A6** | `cancelled` odada employer/worker `escrowBalance` unlock edilmiş olmalıdır. |
| **INV-A7** | Settlement sonrası employer `usdtBalance` lockAmount kadar azalmış olmalıdır (doğrulama L1421–1432). |
| **INV-A8** | `finalize_success` settlement wallet commit (`users.json`, L1413) ile treasury commission yazımı (`treasury_ledger.json`, L1435 → `wallet_lib.php` L1814–1817) aynı atomic sınırda değildir; wallet commit sonrası treasury yazımı gerçekleşmezse geri alma veya tamamlama mekanizması yoktur. |

### B. Idempotency

| ID | İnvariant |
|----|-----------|
| **INV-B1** | `completed` oda için `finalize_success` tekrar çağrıldığında wallet mutasyonu yapılmaz (L1318–1326). |
| **INV-B2** | Açık `disputed` oda için `file_dispute` tekrar çağrıldığında deposit tekrar alınmaz (L1692–1700). |
| **INV-B3** | `resolved` dispute için `resolve_dispute` tekrar çağrıldığında wallet mutasyonu yapılmaz (L1824–1826). |
| **INV-B4** | Concurrent `accept_terms`: yalnızca bir istek `terms_pending`→`locking` claim edebilir (L766–785). |
| **INV-B5** | Concurrent `finalize`: yalnızca bir istek `settling` claim edebilir (L1367–1389). |

### C. Transient recovery

| ID | İnvariant |
|----|-----------|
| **INV-C1** | `settlingWalletApplied=true` iken TTL recovery odayı `completed` yapar (L92–102). |
| **INV-C2** | `settlingWalletApplied` yokken TTL recovery odayı `settlingPreviousStatus`'a revert eder (L104–112). |
| **INV-C3** | Recovery işlemleri wallet dosyasına yazmaz (L59–153 — yalnızca `escrow_rooms.json`). |

### D. Rollback / exception

| ID | İnvariant |
|----|-----------|
| **INV-D1** | `zinesh_wallet_apply_tl_escrow_settlement` hata durumunda `zinesh_wallet_abort` exception fırlatır; `exit` kullanmaz (L595–657). |
| **INV-D2** | `accept_terms` içinde `Throwable` yakalandığında `rollbackRoom` closure çalışır (L831–834, L848–855). |
| **INV-D3** | `zinesh_json_response` çağrıldığında hiçbir rollback closure çalışmaz (`_bootstrap.php` L189 `exit`). |
| **INV-D4** | `zinesh_update_user` içinde `users.json`'da eşleşen `uid` yoksa `zinesh_json_response` ile `exit` eder (`wallet_lib.php` L589); çağıran escrow flow'un `try/catch` veya rollback closure'ı çalışmaz. |

#### INV-D4 — `zinesh_update_user` kullanıcı bulunamadı exit (`wallet_lib.php` L589)

| Alan | Kanıt (dosya + satır) |
|------|------------------------|
| **Tetikleyici** | `zinesh_update_user` içindeki `users.json` atomic callback'te `uid` eşleşmesi yoksa `zinesh_json_response(..., 404)` → `exit` (`wallet_lib.php` L579–589; `exit` mekanizması `_bootstrap.php` L189). |
| **Mutator çalışmaz** | `foreach` döngüsünde eşleşme olmadan L589'a ulaşılır; L585 `$mutator($users[$i])` **hiç çağrılmaz**. |
| **Rollback neden atlanır** | `exit` process'i sonlandırır; çağıran flow'daki `try/catch` (`accept_terms` L831–834), `rollbackRoom` closure (L790–818) veya `file_dispute` refund (L1777) **çalışmaz**. |

**`escrow_room_lib.php` içinden `zinesh_update_user` çağrıları ve ilişkili flow'lar:**

| Flow | Fonksiyon | Çağrı satırları | L589 anındaki oda durumu (uid yoksa) |
|------|-----------|-----------------|--------------------------------------|
| TTL `locking` recovery | `zinesh_escrow_room_release_locking_hold` | L41 (employer), L47 (worker) — tetikleyici L127 | Recovery sırasında; oda `terms_pending`'e alınmak üzere (`escrow_room_lib.php` L71–84) |
| `accept_terms` — employer lock | `zinesh_escrow_room_accept_terms` | L823 | Oda `locking` (claim L774–775 commit edilmiş) |
| `accept_terms` — worker collateral | `zinesh_escrow_room_accept_terms` | L840 | Oda `locking`; employer lock önceki adımda commit edilmiş olabilir (L835) |
| `accept_terms` — employer rollback (catch) | `zinesh_escrow_room_accept_terms` | L849 | Collateral fail sonrası; oda `locking` |
| `accept_terms` — finalize fail rollback | `zinesh_escrow_room_accept_terms` | L904, L909 | Oda `locking`; bir veya iki wallet adımı commit edilmiş (L903–915) |
| `file_dispute` — deposit collect | `zinesh_escrow_dispute_collect_filer_deposit` | L1583 | Oda henüz `disputed` değil (room write L1719 **sonra**) |
| `file_dispute` — deposit refund | `zinesh_escrow_dispute_refund_filer_deposit` | L1617 | Room write fail sonrası (L1777) |
| `request_cancel` — unlock | `zinesh_escrow_room_request_cancel` | L2055 (employer), L2063 (worker) | Oda `cancelled` **önce** commit edilmiş (L2017) |

**Not:** L589, mutator içindeki bakiye yetersizliği (`L827`, `L844`, `L1587`) senaryosundan **ayrıdır**; o senaryolar INV-D3 kapsamındadır. L589 yalnızca `users.json` kaydında `uid` bulunamadığında tetiklenir.

### E. Gözlem katmanı (escrow_memory_lib.php)

| ID | İnvariant |
|----|-----------|
| **INV-E1** | `accept_terms` başarı → `zinesh_escrow_memory_on_terms_accepted` (L939–943). |
| **INV-E2** | `finalize_success` başarı → `zinesh_escrow_memory_on_settlement_completed` (L1516). |
| **INV-E3** | `finalize_success` hata → `zinesh_escrow_memory_on_settlement_failed` (L1496). |
| **INV-E4** | `file_dispute` başarı → `zinesh_escrow_memory_on_dispute_opened` (L1799). |
| **INV-E5** | `resolve_dispute` başarı → `zinesh_escrow_memory_on_dispute_resolved` (L1958). |
| **INV-E6** | `request_cancel` → **hiçbir** memory hook yok (L1977–2085 arası çağrı yok). |
| **INV-E7** | Memory hook hataları `zinesh_escrow_memory_safe` ile yutulur; ana flow etkilenmez (`escrow_memory_lib.php` — ana escrow akışını bloklamaz). |

---

## 3. İnvariant Doğrulama Tablosu

| ID | İhlal? | Kanıt | İhlal senaryosu | Recovery | Risk | Doğrulama testi |
|----|--------|-------|-----------------|----------|------|-----------------|
| **INV-A1** | Hayır | L71–84, L127, L33–51 | — | TTL otomatik | — | Sim: locking expire + funded flags → escrow düşer |
| **INV-A2** | Hayır* | L863–901, L823–830 | *L827 exit bypass ile kısmi lock | TTL | Orta | E2E accept_terms happy path |
| **INV-A3** | Hayır | L1478–1495: `walletSettled=true` → revert yok | — | partial audit | — | Unit: mock wallet OK + throw after → room stays settling |
| **INV-A4** | **Evet** | L104–112 recovery; L1413 wallet; L1439 flag **sonra** | Wallet commit (L1413) → crash before L1439 → TTL → `settling_revert` | Oda `locked`'a döner; wallet commit kalır | **Kritik** | Crash test: kill after L1413, wait TTL, assert room≠wallet |
| **INV-A5** | **Evet** | L1869 wallet; L1917 room — arada fail yok | Wallet OK, room atomic fail → `disputed` kalır | Manuel admin | **Kritik** | Sim: users write OK, rooms write fail |
| **INV-A6** | **Evet** | L2017 cancelled; L2057 `if escrow >= amount` sessiz skip | Unlock fail veya partial | Yok | Yüksek | Cancel + corrupt escrowBalance |
| **INV-A7** | Kısmen | L1421–1432 doğrulama; mismatch → throw L1432 | Doğrulama fail → partial state | catch partial L1493 | Yüksek | Settlement debit mismatch audit |
| **INV-A8** | **Evet** | L1413 wallet atomic (`wallet_lib.php` L619–663); L1435 treasury (`wallet_lib.php` L1985, L1814–1817); recovery treasury'ye dokunmaz (`escrow_room_lib.php` L59–153) | Wallet commit → crash before L1435 → treasury yazılmadı; recovery treasury tamamlamaz | Yok (treasury); oda için bkz. §5.8 | Orta (muhasebe) | Crash after L1413: users.json mutated, treasury_ledger.json unchanged |
| **INV-B1** | Hayır | L1318–1326 | — | — | — | Double finalize call |
| **INV-B2** | Hayır | L1692–1700 | — | — | — | Double file_dispute |
| **INV-B3** | Hayır | L1824–1826 | — | — | — | Double resolve_dispute |
| **INV-B4** | Hayır | L766–785 claim | — | — | — | Concurrent accept_terms sim |
| **INV-B5** | Hayır | L1367–1389 claim | — | — | — | Concurrent finalize sim |
| **INV-C1** | Hayır | L92–102 | — | TTL complete | — | settling + flag + expire |
| **INV-C2** | Tasarım gereği | L104–112 | Wallet commit edilmiş ama flag yok → revert (INV-A4 ile çakışır) | Yanlış recovery | **Kritik** | A4 ile aynı |
| **INV-C3** | Hayır | L59–153 | — | — | — | Recovery sonrası users.json checksum |
| **INV-D1** | Hayır | L595–657 `zinesh_wallet_abort` | — | catch revert | — | Invalid lock amount |
| **INV-D2** | Kısmen | L831–834 catch | L827 `json_response` exit → catch çalışmaz | TTL | Yüksek | Insufficient balance in mutator |
| **INV-D3** | **Evet** | `_bootstrap.php` L189; L827,844,1237,1244,1587,1887,1892,1999,2006 | Bakiye yetersiz exit → locking stuck | TTL/manual | Yüksek | Mutator exit + room state assert |
| **INV-D4** | **Evet** | `wallet_lib.php` L589; çağrılar `escrow_room_lib.php` L41,47,823,840,849,904,909,1583,1617,2055,2063 | `users.json`'da uid yok → exit; oda önceden mutate edilmiş olabilir (`locking`/`cancelled`) | Yok (exit) | Yüksek | Sim: room uid not in users during accept_terms |
| **INV-E1** | Hayır | L939–943 | — | — | — | Memory e2e accept |
| **INV-E2** | Kısmen | L1516 after room complete | Complete fail ama wallet OK → memory failed event only | — | Düşük | Partial settlement |
| **INV-E3** | Hayır | L1496 | — | — | — | Force settlement error |
| **INV-E4** | Hayır | L1799 | — | — | — | Dispute e2e |
| **INV-E5** | Kısmen | L1958 after room resolved | Wallet OK, room fail → memory yok | — | Orta | Resolve partial |
| **INV-E6** | **Evet** | L1977–2085: memory çağrısı yok | Cancel her zaman gözlemsiz | Yok | Orta (gözlem) | Cancel → events empty |
| **INV-E7** | Tasarım | `escrow_memory_lib.php` safe wrapper | Event kaybı sessiz | Yok | Düşük | Memory throw sim |

---

## 4. `zinesh_json_response()` — Atomic / Mutator İçi Çağrılar

`zinesh_json_response` her zaman `exit` yapar (`_bootstrap.php` L184–189). Aşağıdaki çağrılar rollback veya atomic callback tamamlanmasını engelleyebilir.

| # | Dosya | Satır | Flow | Blok tipi | Rollback neden atlanır? |
|---|-------|-------|------|-----------|------------------------|
| 1 | `escrow_room_lib.php` | 827 | `accept_terms` | `zinesh_update_user` mutator (users.json atomic içi) | `exit` → L831 `catch` ve L790 `rollbackRoom` çalışmaz. Oda `locking` (L774 claim commit edilmiş). Wallet `escrowBalance` artmış olabilir (L829 commit). |
| 2 | `escrow_room_lib.php` | 844 | `accept_terms` | `zinesh_update_user` mutator (collateral) | Aynı. Ek olarak employer lock önceki adımda commit edilmiş olabilir (L835). |
| 3 | `escrow_room_lib.php` | 1237 | `confirm_complete` | `zinesh_json_atomic` callback (rooms) | `exit` → callback yarım; atomic writer commit etmiş olabilir (L1258 `return true`). Onay flag'i yazılmış olabilir. |
| 4 | `escrow_room_lib.php` | 1244 | `confirm_complete` | `zinesh_json_atomic` callback (rooms) | `exit` → yetkisiz kullanıcı; önceki satırlarda mutation yok. |
| 5 | `escrow_room_lib.php` | 1587 | `file_dispute` | `zinesh_update_user` mutator (deposit) | `exit` → L1592 `catch` çalışmaz. Deposit henüz commit edilmemiş (mutator içi fail). |
| 6 | `escrow_room_lib.php` | 1887 | `resolve_dispute` | `zinesh_json_atomic` callback (users.json) | `exit` → users atomic abort; room hâlâ `disputed`. Wallet değişmedi. |
| 7 | `escrow_room_lib.php` | 1892 | `resolve_dispute` | `zinesh_json_atomic` callback (users.json) | Aynı — yetersiz escrow, wallet abort. |
| 8 | `escrow_room_lib.php` | 1999 | `request_cancel` | `zinesh_json_atomic` callback (rooms) | `exit` → yanlış status; mutation yok (L1998'de fail öncesi). |
| 9 | `escrow_room_lib.php` | 2006 | `request_cancel` | `zinesh_json_atomic` callback (rooms) | `exit` → yetkisiz; mutation yok. |

### İlişkili (escrow flow dışı ama `zinesh_update_user` üzerinden)

| Dosya | Satır | Flow | Not |
|-------|-------|------|-----|
| `wallet_lib.php` | 589 | `zinesh_update_user` | Kullanıcı bulunamadı → exit; escrow mutator'dan önce fail |

---

## 5. Settlement Özel İncelemesi

### Soru

> Wallet debit/credit commit edildikten sonra hangi kod yolları room state'ini geri çevirebilir?

### Cevap (koddan ispat)

#### 5.1 `finalize_success` catch bloğu — GERİ ÇEVİRMEZ (wallet commit sonrası)

```php
// escrow_room_lib.php L1411–1414
$walletSettled = false;
try {
    zinesh_wallet_apply_tl_escrow_settlement(...);  // L1413 — WALLET COMMIT
    $walletSettled = true;                           // L1414
    ...
} catch (Throwable $e) {
    if (!$walletSettled) {                           // L1479 — yalnızca false iken
        // settling → previousStatus REVERT          // L1480–1492
    } else {
        zinesh_audit('escrow_room_settlement_partial', ...);  // L1494 — REVERT YOK
    }
}
```

**Sonuç:** `zinesh_wallet_apply_tl_escrow_settlement` başarılı olduktan sonra (`$walletSettled === true`), `finalize_success` içindeki catch bloğu odayı **geri çevirmez**.

---

#### 5.2 TTL Recovery — GERİ ÇEVİREBİLİR (wallet commit sonrası, flag yoksa)

Zincir:

```
1. L1413  zinesh_wallet_apply_tl_escrow_settlement()
          → users.json COMMIT (employer debit + worker credit)

2. [CRASH veya EXCEPTION before L1439]

3. L1439  zinesh_escrow_room_patch_fields(..., settlingWalletApplied: true)
          → ÇALIŞMADI

4. Oda: status=settling, settlingWalletApplied UNSET

5. L256   zinesh_escrow_room_find() → recover_stale_transient_if_needed()

6. L87–88 settling TTL expired?

7. L92    if (!empty($row['settlingWalletApplied'])) → FALSE

8. L104–112  action = 'settling_revert'
             rows[i].status = settlingPreviousStatus  // locked | completion_pending
```

**Kanıt satırları:**

- Wallet commit: `wallet_lib.php` L619–663 (`zinesh_json_atomic` users.json)
- Flag yazımı wallet **sonrası**: `escrow_room_lib.php` L1439
- Recovery revert koşulu: `escrow_room_lib.php` L92–93 (flag yok) → L104–112 (revert)
- Recovery wallet'a **dokunmaz**: `escrow_room_lib.php` L59–153 (yalnızca rooms atomic)

**Sonuç:** Wallet commit edildikten sonra `settlingWalletApplied` yazılmadan process kesilirse, TTL recovery odayı **`locked` veya `completion_pending`'e geri alır**. Bu, production invariant INV-A4'ün **ihlalidir**.

---

#### 5.3 TTL Recovery — GERİ ÇEVİRMEZ (wallet commit + flag var)

```
1. L1413  wallet commit
2. L1439  settlingWalletApplied = true  (COMMIT)
3. [CRASH before L1441 completed]
4. TTL expire → L92 flag VAR → L97 status = completed (settling_complete)
```

Oda ileri alınır, geri alınmaz.

---

#### 5.4 Başka geri çevirme yolları

| Yol | Wallet sonrası revert? | Kanıt |
|-----|------------------------|-------|
| `finalize_success` catch (`walletSettled=true`) | **Hayır** | L1493–1495 |
| `recover_stale_transient` `settling_complete` | **Hayır** (ileri) | L92–102 |
| `recover_stale_transient` `settling_revert` | **Evet** (flag yok) | L104–112 |
| `confirm_complete` | **Hayır** | Settlement başladıktan sonra confirm room revert etmez |
| `file_dispute` | **Hayır** | Farklı status path; settlement sonrası değil |
| `request_cancel` | **Hayır** | `locked`/`completion_pending` only; `settling`/`completed` cancel edilemez (L1998) |
| Manuel admin / script | Operasyonel | `reconcile_escrow_sender_debit.php` — düzeltme, revert değil |

---

#### 5.5 Settlement geri çevirme — tam zincir özeti

```
[WALLET COMMIT @ L1413]
        │
        ├─► L1439 flag yazıldı ──► TTL ──► settling_complete (completed)     ✅ Geri yok
        │
        └─► L1439 flag YAZILMADI ──► TTL ──► settling_revert (locked/...)   ❌ GERİ VAR
                                              (wallet commit kalır)
```

**Tek kanıtlanmış wallet-sonrası room-revert yolu:** `zinesh_escrow_room_recover_stale_transient_if_needed` → `settling_revert` (`escrow_room_lib.php` L104–112), koşul: `settlingWalletApplied` boş ve TTL dolmuş.

**Resmi invariant:** Treasury atomicity ayrımı §5.6–5.7'de belgelenmişti; §2'de **INV-A8** olarak tanımlandı. TTL recovery'nin bu senaryoyu treasury açısından ele almadığı §5.8'de doğrulandı.

---

#### 5.6 `zinesh_add_treasury_fee` — wallet commit ile aynı atomic sınırda mı?

**Soru:** `treasury_ledger.json` yazımı, settlement wallet commit ile aynı atomic işlem içinde mi?

**Cevap: Hayır.** Koddan ispat:

| Adım | Fonksiyon | Dosya | Satır | Atomic sınır |
|------|-----------|-------|-------|--------------|
| 1 | `zinesh_wallet_apply_tl_escrow_settlement` | `wallet_lib.php` | L619–663 | `zinesh_json_atomic('users.json', ...)` — **users.json tek dosya atomic** |
| 2 | `zinesh_distribute_commission` | `wallet_lib.php` | L1985 | `zinesh_add_treasury_fee(...)` çağrısı |
| 3 | `zinesh_add_treasury_fee` | `wallet_lib.php` | L1814–L1817 | `zinesh_json_read('treasury_ledger.json')` (L1814) ardından `zinesh_json_write('treasury_ledger.json', $ledger)` (L1817) — **ayrı dosya; `users.json` atomic bloğu dışında** |
| Çağrı zinciri | `finalize_success` | `escrow_room_lib.php` | L1413 → L1435 | Wallet commit (L1413) tamamlandıktan **sonra** treasury yazımı (L1435) |

`zinesh_json_write` (`_bootstrap.php` L115–120) dosya başına `LOCK_EX` kullanır; ancak bu, `users.json` üzerindeki `zinesh_json_atomic` (L128+) ile **tek cross-file transaction oluşturmaz**. İki ayrı dosya, iki ayrı commit.

**`resolve_dispute` için aynı ayrım:** `users.json` atomic L1869–1906; ardından `zinesh_distribute_commission` / `zinesh_add_treasury_fee` L1908–1914; oda `resolved` yazımı L1917–1947 — üç ayrı commit aşaması.

---

#### 5.7 Senaryo: wallet commit → crash → treasury write

**Zincir (`finalize_success`):**

```
L1413  zinesh_wallet_apply_tl_escrow_settlement()
       → users.json COMMIT (employer debit + worker credit; wallet_lib.php L619–663)

       [CRASH — process sonlanır]

L1435  zinesh_distribute_commission($commission, 'escrow_room', 'TL')
       → zinesh_add_treasury_fee('protocol_commission_tl', $system)  (wallet_lib.php L1985)
       → treasury_ledger.json yazımı  (wallet_lib.php L1814–1817)
       → ÇALIŞMADI
```

**Commit edilmiş sayılan taraf:**

| Store | Durum | Kanıt |
|-------|-------|-------|
| `users.json` | **Commit edilmiş** | L1413 `zinesh_json_atomic` callback `return true` (L662) ile tamamlanmış olmalı; L1414 `$walletSettled = true` yalnızca bu satırdan sonra çalışır |
| `treasury_ledger.json` | **Commit edilmemiş** | L1435 henüz çalışmadı veya L1985/L1817 tamamlanmadı |
| `escrow_rooms.json` | **Commit edilmemiş** (bu aşamada) | `settlingWalletApplied` patch L1439 ve `completed` L1441–1458 henüz çalışmadı; oda `settling` (claim L1382–1385) |

**Crash treasury yazımı sırasında (`L1814` read tamam, `L1817` write yarım):** Kodda iki aşamalı commit veya WAL yok; `zinesh_json_write` (L115–120) tek `file_put_contents` çağrısıdır. Kesin davranış dosya seviyesinde tanımlı değildir; belge yalnızca L1435 öncesi crash'te treasury'nin güncellenmediğini kanıtlar.

**Catch bloğu bu senaryoyu geri almaz:** Wallet commit sonrası treasury fail veya crash, `$walletSettled === true` ise catch odayı revert etmez (`escrow_room_lib.php` L1493–1495); treasury için ayrı rollback yok.

---

#### 5.8 Settlement + Treasury + TTL Recovery — kod doğrulaması

**Senaryo zinciri:**

```
L1413  zinesh_wallet_apply_tl_escrow_settlement()  → users.json COMMIT
       [CRASH — L1435 öncesi]
L1435  zinesh_distribute_commission()              → ÇALIŞMADI
L1439  settlingWalletApplied patch                → ÇALIŞMADI
       Oda: status=settling, settlingWalletApplied UNSET
       … TTL (600s) …
L256   zinesh_escrow_room_find() → recover_stale_transient_if_needed()
```

**TTL recovery bu durumu nasıl sınıflandırıyor?**

| Soru | Cevap (koddan) | Kanıt |
|------|----------------|-------|
| Recovery fonksiyonu | `settling` + TTL dolmuş + `settlingWalletApplied` boş → `action = 'settling_revert'` | `escrow_room_lib.php` L87–88, L92–93, L104 |
| Sınıflandırma | **Tamamlanmamış settlement** (oda geri alınır) | L104–112: `status` → `settlingPreviousStatus` (`locked` \| `completion_pending`) |
| Alternatif dal | `settlingWalletApplied` dolu ise → `settling_complete` (L92–102) — bu senaryoda **tetiklenmez** (L1439 çalışmadı) | L92–93 |

**Wallet commit edilmemiş gibi mi davranıyor?**

**Hayır (oda tarafı), evet (wallet tarafı tutarsız).** Recovery yalnızca `escrow_rooms.json` yazar (`L64–120`); `users.json`'a **hiç dokunmaz**. `settling_revert` dalında wallet geri alma çağrısı yok (karşılaştır: `locking_revert` → `release_locking_hold` L127; `settling_revert` → yalnızca mesaj/audit/memory L138–146).

Wallet gerçekte commit edilmiş kalır (`wallet_lib.php` L619–663 tamamlanmış). Recovery bunu okumaz ve geri almaz → **INV-C3** ile tutarlı.

**Wallet commit edilmiş gibi mi davranıyor?**

**Kısmen.** `settling_complete` dalı (L92–102) wallet commit'i `settlingWalletApplied` flag'i üzerinden **dolaylı** varsayar; flag yoksa bu dal çalışmaz. Recovery, `users.json`'u okuyarak wallet durumunu doğrulamaz.

**Treasury yazımını tamamlamaya çalışıyor mu?**

**Hayır.** `zinesh_escrow_room_recover_stale_transient_if_needed` içinde:

- `zinesh_distribute_commission` çağrısı yok
- `zinesh_add_treasury_fee` çağrısı yok
- `treasury_ledger.json` referansı yok

Kanıt: L59–153 fonksiyon gövdesi — yalnızca `ZINESH_ESCROW_ROOMS_FILE` atomic (L64), ardından mesaj/audit/memory (L126–149).

**Senaryo hiç ele alınmamış mı?**

**Treasury açısından: evet, ele alınmamış.** Recovery ve catch bloğu treasury'yi ne yazar ne geri alır (L1493–1495 treasury rollback yok).

**Oda açısından:** `settling_revert` (L104–112) çalışır — oda `locked`/`completion_pending`'e döner; wallet ise commit edilmiş kalır. Bu, **INV-A4** ile aynı split-brain sınıfı; treasury eksikliği ek bir muhasebe tutarsızlığıdır (**INV-A8**).

**Özet tablo (bu senaryo sonrası store durumu):**

| Store | TTL `settling_revert` sonrası | Recovery müdahalesi |
|-------|------------------------------|---------------------|
| `users.json` | Wallet commit **kalır** | Yok (L59–153) |
| `treasury_ledger.json` | Commission yazılmamış **kalır** | Yok |
| `escrow_rooms.json` | `locked` veya `completion_pending` | `settling_revert` (L104–112) |

---

## 6. Cross-File Yazım Sırası Referansı

Production hardening sprint'leri için doğrulanmış sıralar:

### accept_terms

| Adım | Dosya | Satır |
|------|-------|-------|
| 1 | rooms: claim `locking` | L766–785 |
| 2 | users: employer lock | L823–830 |
| 3 | rooms: patch flag | L835 |
| 4 | users: worker collateral (opt) | L840–847 |
| 5 | rooms: patch flag (opt) | L856–859 |
| 6 | rooms: finalize `locked` | L863–901 |
| 7 | memory/audit/signals | L918–954 |

### finalize_success

| Adım | Dosya | Satır |
|------|-------|-------|
| 1 | rooms: claim `settling` | L1367–1389 |
| 2 | memory: settlement_started | L1393 |
| 3 | users: settlement | L1413 |
| 4 | users: debit verify | L1416–1432 |
| 5 | treasury: commission | L1435 (`zinesh_distribute_commission` → non-atomic RMW) |
| 6 | rooms: `settlingWalletApplied` | L1439 |
| 7 | rooms: `completed` | L1441–1458 |
| 8 | memory/audit/signals | L1500–1528 |

### resolve_dispute

| Adım | Dosya | Satır |
|------|-------|-------|
| 1 | users: wallet mutation | L1869–1906 |
| 2 | treasury: commission + forfeit | L1908–1915 |
| 3 | rooms: `resolved` | L1917–1947 |
| 4 | memory/audit/trust | L1949–1971 |

### file_dispute

| Adım | Dosya | Satır |
|------|-------|-------|
| 1 | users: deposit (worker_balance only) | L1583–1590 |
| 2 | rooms: `disputed` | L1719–1774 |
| 3 | refund on fail | L1777 |
| 4 | memory/audit | L1793–1799 |

### request_cancel

| Adım | Dosya | Satır |
|------|-------|-------|
| 1 | rooms: flag veya `cancelled` | L1985–2025 |
| 2 | users: unlock | L2054–2068 |
| 3 | trust recalc | L2072–2077 |

---

## 7. Sprint Öncelik Eşlemesi (Bu Belgeye Dayalı)

| Sprint | Hedef invariant | Mevcut ihlal |
|--------|-----------------|--------------|
| S1 | INV-A4, INV-C2 | settling_revert after wallet |
| S2 | INV-D3 | json_response in mutators |
| S3 | INV-A5, INV-E5 | resolve_dispute room-after-wallet |
| S4 | INV-A6 | cancel unlock guarantee |
| S5 | INV-E6 | cancel memory events |
| S6 | INV-A8 | treasury atomicity / wallet-treasury reconciliation |

---

## 8. Belge Onayı

| Rol | Ad | Tarih | İmza |
|-----|-----|-------|------|
| Engineering | | | |
| Product | | | |

**Not:** Bu belgede "İhlal: Evet" olan invariantlar, P0 hardening sprint'lerinin kapsamını tanımlar. Sprint implementasyonu bu belgenin ayrı bir versiyonunu (v1.1) güncellemeden production'a alınmamalıdır.

---

*Kaynak dosyalar: `api/escrow_room_lib.php`, `api/wallet_lib.php`, `api/escrow_memory_lib.php`, `api/_bootstrap.php` — commit anındaki satır numaraları.*
