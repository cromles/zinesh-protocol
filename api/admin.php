<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/withdraw_executor.php';
require_once __DIR__ . '/security_lib.php';
require_once __DIR__ . '/founder_lib.php';
require_once __DIR__ . '/campaign_lib.php';
require_once __DIR__ . '/early_access_lib.php';
require_once __DIR__ . '/email_lib.php';
require_once __DIR__ . '/tl_havale_lib.php';
require_once __DIR__ . '/founder_profile_lib.php';
require_once __DIR__ . '/escrow_room_lib.php';

header('Content-Type: text/html; charset=utf-8');

zinesh_admin_require_html();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    zinesh_admin_verify_csrf();
    if (isset($_POST['auto_send'])) {
        zinesh_recover_withdrawal_by_id((string)$_POST['auto_send']);
        zinesh_admin_redirect();
    }
    if (isset($_POST['approve_manual_review'])) {
        zinesh_approve_manual_review_withdrawal((string)$_POST['approve_manual_review']);
        zinesh_admin_redirect();
    }
    if (isset($_POST['refund'])) {
        zinesh_refund_withdrawal((string)$_POST['refund']);
        zinesh_admin_redirect();
    }
    if (isset($_POST['mark_paid'])) {
        $id = (string)$_POST['mark_paid'];
        $txOut = trim((string)($_POST['tx_out'] ?? ''));
        zinesh_json_atomic('withdrawals.json', static function (array &$withdrawals) use ($id, $txOut): bool {
            foreach ($withdrawals as &$w) {
                if (($w['id'] ?? '') === $id && ($w['status'] ?? '') === 'pending') {
                    $w['status'] = 'completed';
                    $w['paidAt'] = date('c');
                    $w['outboundTx'] = $txOut;
                    $w['manual'] = true;
                    return true;
                }
            }
            return false;
        });
        zinesh_admin_redirect();
    }
    if (isset($_POST['run_cron'])) {
        zinesh_process_pending_withdrawals(20);
        zinesh_admin_redirect();
    }
    if (isset($_POST['approve_kyc'])) {
        $uid = trim((string)$_POST['approve_kyc']);
        zinesh_campaign_claim_kyc($uid);
        zinesh_admin_redirect();
    }
    if (isset($_POST['smtp_test'])) {
        $to = trim((string)$_POST['smtp_test']);
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $mailResult = zinesh_smtp_test_send($to);
        }
        $smtpFlash = isset($mailResult)
            ? ($mailResult['ok'] ? 'SMTP test OK: ' . $to : 'SMTP hata: ' . ($mailResult['error'] ?? ''))
            : 'Geçersiz e-posta';
        zinesh_admin_redirect('smtp_msg=' . urlencode($smtpFlash));
    }
    if (isset($_POST['resolve_escrow_dispute'])) {
        $roomId = trim((string)$_POST['resolve_escrow_dispute']);
        $fault = trim((string)($_POST['dispute_fault'] ?? 'split'));
        $note = trim((string)($_POST['dispute_note'] ?? ''));
        $result = zinesh_escrow_room_resolve_dispute($roomId, $fault, $note);
        $msg = $result['ok'] ? 'Emanet şikayeti karara bağlandı.' : ($result['message'] ?? 'Hata');
        zinesh_admin_redirect('dispute_msg=' . urlencode($msg));
    }
    if (isset($_POST['approve_havale'])) {
        $hid = (string)$_POST['approve_havale'];
        foreach (zinesh_havale_list_pending() as $row) {
            if (($row['id'] ?? '') === $hid) {
                zinesh_havale_credit_deposit($row, 'admin_panel');
                break;
            }
        }
        zinesh_admin_redirect('havale_msg=' . urlencode('Havale onaylandı.'));
    }
    if (isset($_POST['set_user_balance'])) {
        $lookup = trim((string)($_POST['balance_lookup'] ?? ''));
        $amount = (float)($_POST['balance_amount'] ?? 0);
        $mode = trim((string)($_POST['balance_mode'] ?? 'add'));
        $note = trim((string)($_POST['balance_note'] ?? ''));
        $result = zinesh_admin_set_user_balance($lookup, $amount, $mode, $note);
        zinesh_admin_redirect('balance_msg=' . urlencode((string)($result['message'] ?? 'İşlem sonucu yok.')));
    }
    if (isset($_POST['save_early_access'])) {
        zinesh_early_access_save([
            'enabled' => !empty($_POST['ea_enabled']),
            'title' => (string)($_POST['ea_title'] ?? ''),
            'max_users' => (float)($_POST['ea_max_users'] ?? 500),
            'max_fizi_per_user' => (float)($_POST['ea_max_fizi'] ?? 500),
            'min_swap_usdt' => (float)($_POST['ea_min_swap'] ?? 1),
            'sell_requires_deposit' => !empty($_POST['ea_sell_deposit']),
            'sell_requires_referrals' => (float)($_POST['ea_sell_refs'] ?? 3),
            'sale_requires_current_deposit' => !empty($_POST['ea_sale_current_deposit']),
        ]);
        zinesh_admin_redirect('ea_msg=' . urlencode('Erken erişim ayarları kaydedildi.'));
    }
}

$withdrawals = zinesh_json_read('withdrawals.json');
$ledger = zinesh_json_read('treasury_ledger.json');
$secrets = zinesh_load_secrets();
$pending = array_values(array_filter($withdrawals, fn($w) => ($w['status'] ?? '') === 'pending'));
$pendingManual = array_values(array_filter($withdrawals, fn($w) => ($w['status'] ?? '') === 'pending_manual_review'));
$hotWalletMax = zinesh_hot_wallet_max_usdt();
$tronAuto = zinesh_auto_withdraw_enabled('tron');
$evmAuto = zinesh_auto_withdraw_enabled('arbitrum');
$hotWallets = zinesh_json_read('hot_wallets.json');
$activeTreasury = zinesh_active_treasury();
$kasaOzeti = zinesh_treasury_panel_stats();
$onChain = zinesh_treasury_on_chain_balances();
$tronUsdt = (float)($onChain['tron']['usdt'] ?? 0);
$arbUsdt = (float)($onChain['evm']['arbitrum']['usdt'] ?? 0);
$ethUsdt = (float)($onChain['evm']['ethereum']['usdt'] ?? 0);
$onChainTotal = round($tronUsdt + $arbUsdt + $ethUsdt, 2);
$campaign = zinesh_campaign_public_status();
$earlyAccess = zinesh_early_access_cfg();
$allUsers = zinesh_load_users();
$mailCfg = zinesh_mail_config();
$smtpOn = zinesh_smtp_configured();
$smtpMsg = (string)($_GET['smtp_msg'] ?? '');
$havaleMsg = (string)($_GET['havale_msg'] ?? '');
$balanceMsg = (string)($_GET['balance_msg'] ?? '');
$pendingHavale = zinesh_havale_list_pending();
$eaMsg = (string)($_GET['ea_msg'] ?? '');
$disputeMsg = (string)($_GET['dispute_msg'] ?? '');
$openEscrowDisputes = zinesh_escrow_room_disputes_open();
$resetMsg = (string)($_GET['reset_msg'] ?? '');
$adminCsrf = zinesh_admin_csrf_token();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Zinesh Çekim Yönetimi</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #0a0a0f; color: #e4e4e7; padding: 24px; max-width: 960px; margin: 0 auto; }
    h1 { font-size: 1.25rem; }
    .pill { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; margin-right: 6px; }
    .ok { background: #14532d; color: #86efac; }
    .warn { background: #422006; color: #fcd34d; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 13px; }
    th, td { border: 1px solid #27272a; padding: 8px; text-align: left; vertical-align: top; }
    th { background: #18181b; }
    .mono { font-family: ui-monospace, monospace; font-size: 11px; word-break: break-all; }
    button { background: #7c3aed; color: white; border: none; padding: 6px 10px; border-radius: 8px; cursor: pointer; font-size: 12px; }
    button.danger { background: #b91c1c; }
    input[type=text] { width: 100%; background: #09090e; border: 1px solid #3f3f46; color: white; padding: 6px; border-radius: 6px; font-size: 11px; }
    .ledger { margin-top: 24px; font-size: 12px; color: #a1a1aa; }
    .kasa-ozet { margin-top: 16px; max-width: 420px; background: #09090e; border: 1px solid #27272a; border-radius: 12px; padding: 16px 18px; }
    .kasa-ozet h3 { margin: 0 0 12px; font-size: 13px; color: #fafafa; font-weight: 600; }
    .kasa-row { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding: 7px 0; border-bottom: 1px solid #18181b; font-size: 13px; }
    .kasa-row:last-child { border-bottom: none; }
    .kasa-row span:first-child { color: #a1a1aa; }
    .kasa-row span:last-child { font-family: ui-monospace, monospace; font-weight: 600; color: #fafafa; white-space: nowrap; }
    .kasa-row.highlight span:last-child { color: #86efac; }
    .kasa-row.pool span:last-child { color: #fcd34d; }
    .err { color: #fca5a5; font-size: 11px; }
  </style>
</head>
<body>
  <h1>Zinesh — Çekim merkezi</h1>
  <?php if ($balanceMsg !== ''): ?>
  <p class="ok" style="padding:8px;border-radius:8px;background:#18181b;"><?= htmlspecialchars($balanceMsg) ?></p>
  <?php endif; ?>
  <div class="ledger" style="margin-bottom:20px;">
    <h2>Bakiye tanımla / ekle</h2>
    <p style="font-size:12px;color:#a1a1aa;">Havale geldiğinde veya test için kullanıcı bakiyesine TL ekleyin. E-posta, üye no (ZN-SH-…) veya uid kabul edilir.</p>
    <form method="post" style="display:grid;gap:10px;max-width:520px;background:#09090e;border:1px solid #27272a;border-radius:12px;padding:16px;">
      <?= zinesh_admin_csrf_field($adminCsrf) ?>
      <label style="font-size:12px;color:#a1a1aa;">Kullanıcı
        <input type="text" name="balance_lookup" placeholder="email / ZN-SH-… / uid" required style="margin-top:4px;" />
      </label>
      <label style="font-size:12px;color:#a1a1aa;">Tutar (TL)
        <input type="text" name="balance_amount" placeholder="1000" required style="margin-top:4px;" />
      </label>
      <label style="font-size:12px;color:#a1a1aa;">İşlem
        <select name="balance_mode" style="width:100%;margin-top:4px;background:#09090e;border:1px solid #3f3f46;color:white;padding:6px;border-radius:6px;">
          <option value="add">Ekle (+)</option>
          <option value="set">Mutlak ayarla (=)</option>
        </select>
      </label>
      <label style="font-size:12px;color:#a1a1aa;">Not (opsiyonel)
        <input type="text" name="balance_note" placeholder="Havale dekont / açıklama" style="margin-top:4px;" />
      </label>
      <button type="submit" name="set_user_balance" value="1">Bakiyeyi güncelle</button>
    </form>
  </div>
  <?php if ($havaleMsg !== ''): ?>
  <p class="ok" style="padding:8px;border-radius:8px;background:#18181b;"><?= htmlspecialchars($havaleMsg) ?></p>
  <?php endif; ?>
  <div class="ledger" style="margin-bottom:20px;">
    <h2>TL Havale yatırma (bekleyen)</h2>
    <?php if (count($pendingHavale) === 0): ?>
    <p>Bekleyen havale yok.</p>
    <?php else: ?>
    <table>
      <tr><th>Tarih</th><th>Kullanıcı</th><th>Tutar</th><th>Referans</th><th>Not</th><th></th></tr>
      <?php foreach ($pendingHavale as $h): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars((string)($h['createdAt'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($h['name'] ?? '')) ?><br><span class="mono"><?= htmlspecialchars((string)($h['email'] ?? '')) ?></span></td>
        <td><strong><?= number_format((float)($h['amountTry'] ?? 0), 2) ?> ₺</strong></td>
        <td class="mono"><?= htmlspecialchars((string)($h['reference'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($h['note'] ?? '')) ?></td>
        <td>
          <form method="post" style="margin:0;">
            <?= zinesh_admin_csrf_field($adminCsrf) ?>
            <button type="submit" name="approve_havale" value="<?= htmlspecialchars((string)($h['id'] ?? '')) ?>">Onayla — bakiyeye ekle</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
  <?php if ($disputeMsg !== ''): ?>
  <p class="ok" style="padding:8px;border-radius:8px;background:#18181b;"><?= htmlspecialchars($disputeMsg) ?></p>
  <?php endif; ?>
  <div class="ledger" style="margin-bottom:20px;">
    <h2>Emanet şikayetleri (bekleyen)</h2>
    <p style="font-size:12px;color:#a1a1aa;">Şikayet açıldığında %1 hizmet bedeli kesilmiştir. Kararda toplam protokol payı %6 (%1 + %5) uygulanır. Hatalı tarafın güven oranı düşer.</p>
    <?php if (count($openEscrowDisputes) === 0): ?>
    <p>Açık emanet şikayeti yok.</p>
    <?php else: ?>
    <table>
      <tr><th>Oda</th><th>İş</th><th>Tutar</th><th>Şikayet</th><th>Karar</th></tr>
      <?php foreach ($openEscrowDisputes as $room):
        $dispute = is_array($room['dispute'] ?? null) ? $room['dispute'] : [];
        $employer = zinesh_find_user_by_uid((string)($room['employerUid'] ?? ''));
        $worker = zinesh_find_user_by_uid((string)($room['workerUid'] ?? ''));
      ?>
      <tr>
        <td class="mono"><?= htmlspecialchars((string)($room['id'] ?? '')) ?></td>
        <td>
          <strong><?= htmlspecialchars((string)($room['title'] ?? '')) ?></strong><br>
          <span style="font-size:11px;color:#a1a1aa;">İş veren: <?= htmlspecialchars((string)($employer['name'] ?? '')) ?></span><br>
          <span style="font-size:11px;color:#a1a1aa;">İş alan: <?= htmlspecialchars((string)($worker['name'] ?? '')) ?></span>
        </td>
        <td><strong><?= number_format((float)($room['agreedAmountTry'] ?? 0), 2) ?> ₺</strong></td>
        <td style="font-size:12px;">
          <?= htmlspecialchars((string)($dispute['reason'] ?? '')) ?>
          <?php if (!empty($dispute['evidence'])): ?>
          <br><span class="mono"><?= htmlspecialchars((string)$dispute['evidence']) ?></span>
          <?php endif; ?>
        </td>
        <td>
          <form method="post" style="margin:0;">
            <?= zinesh_admin_csrf_field($adminCsrf) ?>
            <input type="hidden" name="resolve_escrow_dispute" value="<?= htmlspecialchars((string)($room['id'] ?? '')) ?>" />
            <select name="dispute_fault" style="margin-bottom:6px;font-size:12px;">
              <option value="worker">İş alan hatalı — iade iş verene</option>
              <option value="employer">İş veren hatalı — ödeme iş alana</option>
              <option value="split">Paylaştır</option>
              <option value="none">Kimse hatalı değil — iş alana öde</option>
            </select>
            <input type="text" name="dispute_note" placeholder="Admin notu" style="width:100%;margin-bottom:6px;font-size:12px;" />
            <button type="submit" onclick="return confirm('Şikayet karara bağlansın mı?')">Karara bağla</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
  <?php if ($smtpMsg !== ''): ?>
  <p class="<?= str_starts_with($smtpMsg, 'SMTP test OK') ? 'ok' : 'err' ?>" style="padding:8px;border-radius:8px;background:#18181b;"><?= htmlspecialchars($smtpMsg) ?></p>
  <?php endif; ?>
  <div class="ledger" style="margin-bottom:20px;">
    <h2>E-posta (SMTP)</h2>
    <p>
      <span class="pill <?= $smtpOn ? 'ok' : 'warn' ?>"><?= $smtpOn ? 'SMTP aktif' : 'SMTP kapalı — setup_mail.php ile kur' ?></span>
      <span class="mono">Gönderen: <?= htmlspecialchars((string)$mailCfg['from_email']) ?></span>
    </p>
    <?php if ($smtpOn): ?>
    <form method="post" style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <?= zinesh_admin_csrf_field($adminCsrf) ?>
      <input type="email" name="smtp_test" placeholder="test@email.com" required style="max-width:240px;" />
      <button type="submit">SMTP test gönder</button>
    </form>
    <?php else: ?>
    <p class="err">Sunucuda: <span class="mono">php /www/wwwroot/zinesh.com/api/setup_mail.php --host ... --user ... --pass ... --from noreply@zinesh.com --test sizin@email.com</span></p>
    <?php endif; ?>
  </div>
  <p>
    <span class="pill <?= $tronAuto ? 'ok' : 'warn' ?>">TRON otomatik: <?= $tronAuto ? 'Açık' : 'Kapalı' ?></span>
    <span class="pill <?= $evmAuto ? 'ok' : 'warn' ?>">EVM otomatik: <?= $evmAuto ? 'Açık' : 'Kapalı' ?></span>
    <span class="pill warn">Hot wallet otomatik limit: $<?= number_format($hotWalletMax, 0) ?> USDT</span>
  </p>
  <?php if (!empty($activeTreasury['tron'])): ?>
  <div class="ledger">
    <h2>Operasyonel kasa (yatırım + çekim)</h2>
    <p class="mono">TRON: <?= htmlspecialchars($activeTreasury['tron']) ?></p>
    <p class="mono">Arbitrum / ETH: <?= htmlspecialchars($activeTreasury['arbitrum']) ?></p>
    <?php if (!empty($onChain['ok'])): ?>
    <p>Zincirde USDT — TRON: <strong>$<?= number_format($tronUsdt, 2) ?></strong>,
       Arbitrum: <strong>$<?= number_format($arbUsdt, 2) ?></strong>,
       Ethereum: <strong>$<?= number_format($ethUsdt, 2) ?></strong>
       (toplam ~$<?= number_format($onChainTotal, 2) ?>)</p>
    <p>Gas — TRX: <?= number_format((float)($onChain['tron']['trx'] ?? 0), 2) ?>,
       ARB ETH: <?= number_format((float)($onChain['evm']['arbitrum']['eth'] ?? 0), 4) ?>,
       ETH: <?= number_format((float)($onChain['evm']['ethereum']['eth'] ?? 0), 4) ?></p>
    <?php endif; ?>
    <div class="kasa-ozet">
      <h3>Kasa özeti</h3>
      <div class="kasa-row highlight">
        <span>Gerçek Kasa</span>
        <span><?= number_format($kasaOzeti['gercekKasa'], 2) ?> USDT</span>
      </div>
      <div class="kasa-row">
        <span>Kullanıcı Borcu</span>
        <span><?= number_format($kasaOzeti['kullaniciBorcu'], 2) ?> USDT</span>
      </div>
      <div class="kasa-row highlight">
        <span>Kullanılabilir Rezerv</span>
        <span><?= number_format($kasaOzeti['kullanilabilirRezerv'], 2) ?> USDT</span>
      </div>
      <div class="kasa-row pool">
        <span>Sistem komisyonu (biriken)</span>
        <span><?= number_format((float)($kasaOzeti['sistemKomisyonu'] ?? $kasaOzeti['argeGeliri'] ?? 0), 2) ?> ₺</span>
      </div>
    </div>
    <?php if ($kasaOzeti['liquidityGap'] > 0.01): ?>
    <p style="margin-top:10px;">
      <span class="pill warn">Likidite açığı: $<?= number_format($kasaOzeti['liquidityGap'], 2) ?> USDT</span>
    </p>
    <?php endif; ?>
    <p style="color:#a1a1aa;font-size:12px;margin-top:10px">Kullanıcılar bu adreslere yatırır; çekimler aynı cüzdanlardan otomatik gider.</p>
  </div>
  <?php endif; ?>

  <form method="post" style="margin: 12px 0">
    <?= zinesh_admin_csrf_field($adminCsrf) ?>
    <button type="submit" name="run_cron" value="1">Bekleyenleri şimdi işle (cron)</button>
  </form>
  <p><?= count($pendingManual) ?> kurucu onayı bekleyen (hot wallet &gt; $<?= number_format($hotWalletMax, 0) ?>)</p>

  <?php if (count($pendingManual) > 0): ?>
    <table>
      <thead>
        <tr>
          <th>Tarih</th>
          <th>Kullanıcı</th>
          <th>Ağ</th>
          <th>Adres</th>
          <th>Tutar</th>
          <th>Sebep</th>
          <th>İşlem</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pendingManual as $w): ?>
        <tr>
          <td><?= htmlspecialchars($w['createdAt'] ?? '') ?></td>
          <td><?= htmlspecialchars($w['email'] ?? $w['uid'] ?? '') ?></td>
          <td><?= htmlspecialchars($w['network'] ?? '') ?></td>
          <td class="mono"><?= htmlspecialchars($w['address'] ?? '') ?></td>
          <td><strong>$<?= htmlspecialchars((string)($w['amount'] ?? '')) ?></strong></td>
          <td class="err"><?= htmlspecialchars($w['manualReviewReason'] ?? 'Hot wallet limiti') ?></td>
          <td>
            <form method="post" style="margin-bottom:6px">
              <?= zinesh_admin_csrf_field($adminCsrf) ?>
              <button type="submit" name="approve_manual_review" value="<?= htmlspecialchars($w['id'] ?? '') ?>">Kurucu onayı — gönder</button>
            </form>
            <form method="post" onsubmit="return confirm('Bakiye iade edilsin mi?')">
              <?= zinesh_admin_csrf_field($adminCsrf) ?>
              <button type="submit" class="danger" name="refund" value="<?= htmlspecialchars($w['id'] ?? '') ?>">İade et</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <p><?= count($pending) ?> bekleyen talep</p>

  <?php if (count($pending) === 0): ?>
    <p>Bekleyen çekim yok.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Tarih</th>
          <th>Kullanıcı</th>
          <th>Ağ</th>
          <th>Adres</th>
          <th>Tutar</th>
          <th>Hata</th>
          <th>İşlem</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pending as $w): ?>
        <tr>
          <td><?= htmlspecialchars($w['createdAt'] ?? '') ?></td>
          <td><?= htmlspecialchars($w['email'] ?? $w['uid'] ?? '') ?></td>
          <td><?= htmlspecialchars($w['network'] ?? '') ?></td>
          <td class="mono"><?= htmlspecialchars($w['address'] ?? '') ?></td>
          <td><strong>$<?= htmlspecialchars((string)($w['amount'] ?? '')) ?></strong></td>
          <td class="err"><?= htmlspecialchars($w['lastError'] ?? '') ?></td>
          <td>
            <form method="post" style="margin-bottom:6px">
              <?= zinesh_admin_csrf_field($adminCsrf) ?>
              <button type="submit" name="auto_send" value="<?= htmlspecialchars($w['id'] ?? '') ?>">Otomatik gönder</button>
            </form>
            <form method="post" style="margin-bottom:6px">
              <?= zinesh_admin_csrf_field($adminCsrf) ?>
              <input type="hidden" name="mark_paid" value="<?= htmlspecialchars($w['id'] ?? '') ?>" />
              <input type="text" name="tx_out" placeholder="Manuel TXID" />
              <button type="submit" style="margin-top:4px">Manuel onayla</button>
            </form>
            <form method="post" onsubmit="return confirm('Bakiye iade edilsin mi?')">
              <?= zinesh_admin_csrf_field($adminCsrf) ?>
              <button type="submit" class="danger" name="refund" value="<?= htmlspecialchars($w['id'] ?? '') ?>">İade et</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($eaMsg !== ''): ?><p class="pill ok"><?= htmlspecialchars($eaMsg) ?></p><?php endif; ?>
  <?php if ($resetMsg !== ''): ?><p class="pill <?= str_starts_with($resetMsg, 'Hata') ? 'warn' : 'ok' ?>"><?= htmlspecialchars($resetMsg) ?></p><?php endif; ?>

  <div class="ledger" style="margin-bottom:24px;">
    <h2>Erken Erişim Ayarları</h2>
    <form method="post">
      <?= zinesh_admin_csrf_field($adminCsrf) ?>
      <table style="max-width:640px;">
        <tr><td>Aktif</td><td><input type="checkbox" name="ea_enabled" value="1" <?= !empty($earlyAccess['enabled']) ? 'checked' : '' ?> /></td></tr>
        <tr><td>Başlık</td><td><input type="text" name="ea_title" value="<?= htmlspecialchars((string)($earlyAccess['title'] ?? '')) ?>" /></td></tr>
        <tr><td>Max kullanıcı</td><td><input type="number" name="ea_max_users" value="<?= (int)($earlyAccess['max_users'] ?? 500) ?>" min="1" /></td></tr>
        <tr><td>Max FIZI / kullanıcı</td><td><input type="number" name="ea_max_fizi" value="<?= (float)($earlyAccess['max_fizi_per_user'] ?? 500) ?>" min="1" step="1" /></td></tr>
        <tr><td>Min swap (USDT)</td><td><input type="number" name="ea_min_swap" value="<?= (float)($earlyAccess['min_swap_usdt'] ?? 1) ?>" min="0.01" step="0.01" /></td></tr>
        <tr><td>Satış için yatırım şartı</td><td><input type="checkbox" name="ea_sell_deposit" value="1" <?= !empty($earlyAccess['sell_requires_deposit']) ? 'checked' : '' ?> /></td></tr>
        <tr><td>Satış için referans sayısı</td><td><input type="number" name="ea_sell_refs" value="<?= (int)($earlyAccess['sell_requires_referrals'] ?? 3) ?>" min="0" /></td></tr>
        <tr><td>Satış için anlık USDT bakiyesi (yatır-çek koruması)</td><td><input type="checkbox" name="ea_sale_current_deposit" value="1" <?= !empty($earlyAccess['sale_requires_current_deposit']) ? 'checked' : '' ?> /> <span style="color:#71717a;font-size:11px">Kapalıyken yalnızca bir kez yatırım yeterli</span></td></tr>
      </table>
      <p style="margin-top:10px"><button type="submit" name="save_early_access" value="1">Kaydet</button></p>
    </form>
  </div>

  <div class="ledger">
    <h2><?= htmlspecialchars($campaign['title']) ?></h2>
    <p>
      Havuz: <strong><?= number_format($campaign['poolRemaining']) ?> / <?= number_format($campaign['poolTotal']) ?> FİZİ</strong>
      · Kontenjan: <strong><?= (int)$campaign['slotsRemaining'] ?> / <?= (int)$campaign['slotsTotal'] ?></strong>
      · <?= $campaign['active'] ? '<span class="pill ok">Aktif</span>' : '<span class="pill warn">Sona erdi</span>' ?>
    </p>
    <table>
      <thead>
        <tr><th>Eylem</th><th>FİZİ</th></tr>
      </thead>
      <tbody>
        <?php foreach ($campaign['milestones'] as $m): ?>
        <tr><td><?= htmlspecialchars($m['label']) ?></td><td><?= number_format($m['fizi']) ?></td></tr>
        <?php endforeach; ?>
        <tr><td><strong>Toplam (kurucu üye)</strong></td><td><strong><?= number_format($campaign['rewards']['maxPerUser']) ?></strong></td></tr>
      </tbody>
    </table>
    <h3 style="margin-top:16px">KYC onayı (+10 FİZİ)</h3>
    <table>
      <thead><tr><th>E-posta</th><th>Kurucu</th><th>KYC</th><th>FİZİ</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($allUsers as $u):
          if (($u['kycStatus'] ?? 'none') === 'approved') continue;
        ?>
        <tr>
          <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
          <td><?= !empty($u['foundingMember']) ? 'Evet #' . (int)($u['foundingMemberNumber'] ?? 0) : 'Hayır' ?></td>
          <td><?= htmlspecialchars($u['kycStatus'] ?? 'none') ?></td>
          <td><?= number_format((float)($u['fiziBalance'] ?? 0)) ?></td>
          <td>
            <form method="post">
      <?= zinesh_admin_csrf_field($adminCsrf) ?>
              <button type="submit" name="approve_kyc" value="<?= htmlspecialchars($u['uid'] ?? '') ?>">KYC Onayla</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="ledger">
    <h2>Kasa defteri (ham)</h2>
    <p style="color:#71717a;font-size:11px;margin-bottom:8px">Komisyon havuzları yukarıdaki özetten okunur. Aşağıda teknik kayıt.</p>
    <pre><?= htmlspecialchars(json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    <p class="mono">secrets: <?= file_exists(zinesh_secrets_path()) ? 'OK (/www/server/zinesh-data/secrets.json)' : 'EKSİK — otomatik çekim için private key gerekli' ?></p>
  </div>
</body>
</html>
