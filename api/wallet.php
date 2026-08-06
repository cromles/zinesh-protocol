<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/withdraw_executor.php';
require_once __DIR__ . '/campaign_lib.php';
require_once __DIR__ . '/email_lib.php';
require_once __DIR__ . '/founder_lib.php';
require_once __DIR__ . '/founder_profile_lib.php';
require_once __DIR__ . '/tl_mode_lib.php';
require_once __DIR__ . '/escrow_jobs_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    zinesh_json_response(['message' => 'method_not_allowed'], 405);
}

$input = zinesh_input();
$action = (string)($input['action'] ?? '');
$limits = zinesh_config()['rate_limits'] ?? [];

$siteFiziBlocked = ['swap', 'escrow_lock', 'escrow_release', 'settle_escrow', 'escrow_create', 'escrow_job_cancel', 'escrow_join'];
$tlEscrowAllowed = zinesh_tl_mode_enabled() && in_array(
    $action,
    ['escrow_lock', 'escrow_release', 'settle_escrow', 'escrow_create', 'escrow_jobs_list', 'escrow_job_cancel', 'escrow_job_confirm_complete', 'escrow_job_request_cancel', 'escrow_join'],
    true
);
if (!zinesh_site_fizi_ledger_enabled() && !$tlEscrowAllowed && in_array($action, $siteFiziBlocked, true)) {
    zinesh_json_response([
        'message' => zinesh_tl_mode_enabled()
            ? 'Bu işlem şu an kullanılamıyor.'
            : 'Site FIZI devre dışı. FIZI yalnızca MetaMask zincir cüzdanında kullanılır.',
        'siteFiziLedgerEnabled' => false,
        'tlMode' => zinesh_tl_mode_enabled(),
    ], 403);
}

if ($action === 'state') {
    $user = zinesh_require_auth($input);
    $uid = (string)$user['uid'];
    $user = zinesh_campaign_persist_user_fields($uid) ?? $user;
    if (zinesh_is_founder($user)) {
        $user = zinesh_sync_founder_treasury_wallets($uid);
        $bundle = zinesh_founder_wallet_bundle($user, 50);
        require_once __DIR__ . '/founder_platform_lib.php';
        zinesh_json_response([
            'ok' => true,
            'wallet' => $bundle['wallet'],
            'user' => zinesh_public_user(array_merge($user, [
                'isFounder' => true,
            ])),
            'campaign' => $bundle['campaign'],
            'isFounder' => true,
            'founderProfile' => $bundle['founderProfile'],
            'platformStats' => zinesh_founder_platform_stats(),
        ]);
    }
    zinesh_json_response([
        'ok' => true,
        'wallet' => zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
        'campaign' => zinesh_campaign_user_progress($user),
        'isFounder' => zinesh_is_founder($user),
        'tlMode' => zinesh_tl_mode_enabled(),
        'web3Enabled' => zinesh_web3_enabled(),
    ]);
}

if ($action === 'verify_deposit') {
    if (!zinesh_deposits_enabled()) {
        zinesh_json_response([
            'message' => 'Kripto yatırma şu an kapalı. TL yatırma aktif olduğunda buradan devam edebilirsiniz.',
            'depositsEnabled' => false,
        ], 503);
    }
    zinesh_rate_limit('deposit', (int)($limits['deposit'] ?? 10));
    $user = zinesh_require_auth($input);
    $network = strtolower(trim((string)($input['network'] ?? '')));
    $txHash = trim((string)($input['txHash'] ?? ''));
    $cfg = zinesh_config();

    if (!in_array($network, ['tron', 'arbitrum', 'ethereum', 'solana'], true)) {
        zinesh_json_response(['message' => 'Geçersiz ağ. TRON, Arbitrum, Ethereum veya Solana seçin.'], 400);
    }
    if ($txHash === '') {
        zinesh_json_response(['message' => 'İşlem numarası (TXID) gerekli.'], 400);
    }

  $deposit = zinesh_verify_deposit_details($network, $txHash);
    if ($deposit === null || $deposit['amount'] < $cfg['min_deposit_usdt']) {
        zinesh_json_response([
            'message' => 'İşlem doğrulanamadı. USDT\'nin Zinesh kasa adresine gittiğinden ve onaylandığından emin olun. Birkaç dakika bekleyip tekrar deneyin.',
        ], 400);
    }

    $senderCheck = zinesh_deposit_sender_allowed($user, $network, $deposit['senders']);
    if (!$senderCheck['ok']) {
        $msg = match ($senderCheck['reason'] ?? '') {
            'sender_mismatch' => 'Bu işlemin gönderen adresi, hesabınıza bağlı yatırım cüzdanlarıyla eşleşmiyor. Önce Cüzdanım bölümünden gönderim yapacağınız adresi ekleyin.',
            'sender_unknown' => 'İşlem gönderen adresi zincirden okunamadı. Birkaç dakika bekleyip tekrar deneyin.',
            default => 'Yatırım doğrulanamadı.',
        };
        zinesh_json_response(['message' => $msg], 400);
    }

    $amount = $deposit['amount'];

    if (!zinesh_mark_tx_used($network, $txHash)) {
        zinesh_json_response(['message' => 'Bu işlem numarası daha önce kullanıldı.'], 409);
    }

    $txKey = $network . ':' . strtolower($txHash);

    $uid = $user['uid'];
    $user = zinesh_update_user($uid, function (&$u) use ($amount) {
        $u['usdtBalance'] = round((float)$u['usdtBalance'] + $amount, 6);
    });

    zinesh_append_tx_log($uid, [
        'id' => 'dep-' . substr(hash('sha256', $txKey), 0, 12),
        'type' => 'deposit',
        'network' => $network,
        'amount' => number_format($amount, 2, '.', ''),
        'asset' => 'USDT',
        'txHash' => $txHash,
        'date' => date('d.m.Y H:i'),
        'status' => 'completed',
    ]);

    zinesh_audit('deposit', ['uid' => $uid, 'network' => $network, 'amount' => $amount]);

    require_once __DIR__ . '/campaign_lib.php';
    $depositTask = zinesh_campaign_record_deposit($uid, $amount);
    $user = zinesh_find_user_by_uid($uid);

    zinesh_json_response([
        'ok' => true,
        'message' => sprintf('$%s USDT bakiyenize eklendi.', number_format($amount, 2)),
        'wallet' => zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
        'campaignTask' => $depositTask,
    ]);
}

if ($action === 'report_havale_deposit') {
    require_once __DIR__ . '/tl_havale_lib.php';
    zinesh_rate_limit('deposit', (int)($limits['deposit'] ?? 10));
    $user = zinesh_require_auth($input);
    $amountTry = (float)($input['amountTry'] ?? 0);
    $note = trim((string)($input['note'] ?? ''));
    $result = zinesh_havale_report_deposit($user, $amountTry, $note);
    if (empty($result['ok'])) {
        zinesh_json_response($result, 400);
    }
    zinesh_json_response($result);
}

if ($action === 'swap') {
    zinesh_json_response(['message' => 'Takas işlemi bu sürümde kullanılamıyor.'], 403);
}

if ($action === 'withdraw_request') {
    if (zinesh_tl_mode_enabled()) {
        zinesh_json_response(['message' => 'TL modunda çekim site cüzdanı üzerinden yapılır; zincir çekimi kapalı.'], 403);
    }
    zinesh_rate_limit('withdraw', (int)($limits['withdraw'] ?? 5));
    $user = zinesh_require_auth($input);
    $network = strtolower(trim((string)($input['network'] ?? 'tron')));
    $address = trim((string)($input['address'] ?? ''));
    $amount = (float)($input['amount'] ?? 0);
    $cfg = zinesh_config();

    if ($amount < $cfg['min_withdraw_usdt']) {
        zinesh_json_response(['message' => 'Minimum çekim: $' . $cfg['min_withdraw_usdt'] . ' USDT'], 400);
    }
    $addrErr = zinesh_validate_withdraw_address($network, $address);
    if ($addrErr) {
        zinesh_json_response(['message' => $addrErr], 400);
    }
    $compatErr = zinesh_address_compatible_network($network, $address);
    if ($compatErr) {
        zinesh_json_response(['message' => $compatErr], 400);
    }
    if (zinesh_auto_withdraw_enabled($network)) {
        $onChainCheck = zinesh_hot_usdt_check($network);
        if (!$onChainCheck['ok']) {
            zinesh_json_response([
                'message' => 'Çekim şu an doğrulanamıyor. Kasa bakiyesi okunamadı — birkaç dakika sonra tekrar deneyin.',
            ], 503);
        }
        if ((float)$onChainCheck['available'] < $amount) {
            zinesh_json_response([
                'message' => sprintf(
                    'Çekim şu an yapılamıyor: kasada $%s USDT var, $%s gerekli. Kısa süre sonra tekrar deneyin.',
                    number_format((float)$onChainCheck['available'], 2),
                    number_format($amount, 2)
                ),
            ], 503);
        }
    }
    $dailyLimit = (float)($cfg['daily_withdraw_limit_usdt'] ?? 5000);
    $uid = $user['uid'];
    $user = zinesh_update_user($uid, function (&$u) use ($amount) {
        zinesh_ensure_wallet_fields($u);
        $available = round((float)$u['usdtBalance'] - (float)$u['escrowBalance'], 6);
        if ($available + 1e-9 < $amount) {
            zinesh_json_response(['message' => 'Yetersiz kullanılabilir bakiye (emanet kilitleri dahil değil).'], 400);
        }
        $u['usdtBalance'] = round((float)$u['usdtBalance'] - $amount, 6);
    });

    $wid = 'wd-' . bin2hex(random_bytes(8));
    $withdrawalEntry = [
        'id' => $wid,
        'uid' => $uid,
        'email' => $user['email'] ?? '',
        'network' => $network,
        'address' => $address,
        'amount' => $amount,
        'status' => 'pending',
        'createdAt' => date('c'),
    ];
    $manualReview = zinesh_auto_withdraw_enabled($network) && zinesh_hot_wallet_blocks_auto_send($network);
    if ($manualReview) {
        $withdrawalEntry['status'] = 'pending_manual_review';
        $withdrawalEntry['manualReviewReason'] = sprintf(
            'Hot wallet USDT limiti aşıldı (>$%s). Kurucu onayı gerekir.',
            number_format(zinesh_hot_wallet_max_usdt(), 0)
        );
    }

    $appendResult = zinesh_withdrawals_append_atomic($uid, $withdrawalEntry, $dailyLimit);
    if (!($appendResult['ok'] ?? false)) {
        zinesh_update_user($uid, function (&$u) use ($amount) {
            zinesh_ensure_wallet_fields($u);
            $u['usdtBalance'] = round((float)$u['usdtBalance'] + $amount, 6);
        });
        if (($appendResult['reason'] ?? '') === 'daily_limit') {
            zinesh_json_response(['message' => 'Günlük çekim limiti aşıldı ($' . $dailyLimit . ' USDT).'], 400);
        }
        zinesh_json_response(['message' => 'Çekim kaydı oluşturulamadı. Lütfen tekrar deneyin.'], 500);
    }

    $txHash = 'pending';
    $status = 'processing';
    $userMessage = 'Çekim talebin alındı. Otomatik gönderim deneniyor...';

    if ($manualReview) {
        $status = 'pending_manual_review';
        $userMessage = 'Çekim talebin alındı. Hot wallet güvenlik limiti nedeniyle kurucu onayı bekleniyor.';
    } elseif (zinesh_auto_withdraw_enabled($network)) {
        $claimed = zinesh_claim_withdrawal_for_processing($wid);
        if ($claimed) {
            $send = zinesh_send_claimed_withdrawal($claimed);
            if (!empty($send['ok'])) {
                $txHash = (string)($send['txHash'] ?? '');
                $status = 'completed';
                $userMessage = 'USDT otomatik olarak adresine gönderildi. TX: ' . $txHash;
            } else {
                $userMessage = 'Talep alındı. Otomatik gönderim şu an başarısız; kısa süre içinde tekrar denenecek veya manuel tamamlanacak.';
            }
        }
    } else {
        if ($network === 'solana') {
            $userMessage = 'Solana çekim talebin alındı. Bu ağda otomatik gönderim yok; kısa süre içinde manuel işlenecek.';
        } else {
            $userMessage = 'Çekim talebin alındı. Onay sonrası belirttiğin adrese USDT gönderilecek.';
        }
    }

    zinesh_append_tx_log($uid, [
        'id' => $wid,
        'type' => 'withdrawal',
        'amount' => number_format($amount, 2, '.', ''),
        'asset' => 'USDT',
        'txHash' => $txHash,
        'date' => date('d.m.Y H:i'),
        'status' => $status === 'completed' ? 'completed' : 'processing',
    ]);

    zinesh_audit('withdraw', ['uid' => $uid, 'network' => $network, 'amount' => $amount, 'status' => $status]);

    $user = zinesh_find_user_by_uid($uid);
    zinesh_json_response([
        'ok' => true,
        'message' => $userMessage,
        'wallet' => zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
        'withdrawal' => ['id' => $wid, 'txHash' => $txHash, 'status' => $status],
    ]);
}

if ($action === 'escrow_create') {
    $user = zinesh_require_auth($input);
    $amount = (float)($input['amount'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $supplierEmail = strtolower(trim((string)($input['supplierEmail'] ?? '')));
    $supplierName = trim((string)($input['supplierName'] ?? ''));
    $deliveryDate = trim((string)($input['deliveryDate'] ?? ''));
    $category = trim((string)($input['category'] ?? 'Emanet'));
    $matchOnly = $supplierEmail === '' || !empty($input['matchOnly']);

    if ($amount <= 0) {
        zinesh_json_response(['message' => 'Geçersiz tutar.'], 400);
    }

    $uid = (string)$user['uid'];
    $useTl = zinesh_escrow_uses_tl();
    $fundsLockedNow = false;

    // Klasik e-posta yolu: oluştururken kilitle.
    // Kod ile eşleşme yolu: karşı taraf katılınca kilitle.
    if (!$matchOnly) {
        $user = zinesh_update_user($uid, function (&$u) use ($amount, $useTl) {
            zinesh_ensure_wallet_fields($u);
            if ($useTl) {
                $available = round((float)$u['usdtBalance'] - (float)$u['escrowBalance'], 2);
                if ($available + 1e-9 < $amount) {
                    zinesh_json_response(['message' => 'Yetersiz TL bakiyesi. Önce site cüzdanına para yatırın.'], 400);
                }
            } else {
                $available = round((float)$u['fiziBalance'] - (float)$u['escrowBalance'], 6);
                if ($available + 1e-9 < $amount) {
                    zinesh_json_response(['message' => 'Yetersiz FİZİ. Önce vitrinden veya ödülden FİZİ edinin.'], 400);
                }
            }
            $u['escrowBalance'] = round((float)$u['escrowBalance'] + $amount, $useTl ? 2 : 6);
        });
        $fundsLockedNow = true;
    } else {
        // Eşleşme beklerken bakiyenin yeterliliğini şimdiden kontrol et (kilitleme yok).
        zinesh_ensure_wallet_fields($user);
        if ($useTl) {
            $available = round((float)$user['usdtBalance'] - (float)$user['escrowBalance'], 2);
            if ($available + 1e-9 < $amount) {
                zinesh_json_response(['message' => 'Yetersiz TL bakiyesi. Önce site cüzdanına para yatırın.'], 400);
            }
        } else {
            $available = round((float)$user['fiziBalance'] - (float)$user['escrowBalance'], 6);
            if ($available + 1e-9 < $amount) {
                zinesh_json_response(['message' => 'Yetersiz FİZİ. Önce vitrinden veya ödülden FİZİ edinin.'], 400);
            }
        }
    }

    $jobResult = zinesh_escrow_job_create(
        $uid,
        $amount,
        $title,
        $description,
        $supplierEmail,
        $supplierName,
        $deliveryDate,
        $category,
        $matchOnly
    );
    if (!$jobResult['ok']) {
        if ($fundsLockedNow) {
            zinesh_update_user($uid, function (&$u) use ($amount, $useTl) {
                zinesh_ensure_wallet_fields($u);
                $u['escrowBalance'] = round(max(0, (float)$u['escrowBalance'] - $amount), $useTl ? 2 : 6);
            });
        }
        zinesh_json_response(['message' => $jobResult['message'] ?? 'Emanet kaydı oluşturulamadı.'], 400);
    }

    $agreementTask = null;
    if (!$matchOnly) {
        $agreementTask = zinesh_campaign_record_agreement($uid);
    }
    $user = zinesh_find_user_by_uid($uid) ?? $user;
    $job = $jobResult['job'];

    zinesh_json_response([
        'ok' => true,
        'wallet' => zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
        'job' => zinesh_escrow_job_public_row($job, $uid),
        'matchCode' => (string)($job['matchCode'] ?? ''),
        'message' => $matchOnly
            ? 'Emanet kodu hazır. WhatsApp’tan karşı tarafa gönderin; katılınca para kilitlenir.'
            : 'Emanet oluşturuldu. Tutar kasada kilitlendi.',
        'campaignTask' => $agreementTask,
    ]);
}

if ($action === 'escrow_join') {
    $user = zinesh_require_auth($input);
    $code = trim((string)($input['code'] ?? $input['matchCode'] ?? ''));
    if ($code === '') {
        zinesh_json_response(['message' => 'Emanet kodu gerekli.'], 400);
    }

    $uid = (string)$user['uid'];
    $result = zinesh_escrow_job_join_by_code($code, $uid);
    if (!$result['ok']) {
        zinesh_json_response(['message' => $result['message'] ?? 'Eşleşme başarısız.'], 400);
    }

    $job = $result['job'];
    $buyerUid = (string)($job['buyerUid'] ?? '');
    $agreementTask = null;
    if ($buyerUid !== '') {
        $agreementTask = zinesh_campaign_record_agreement($buyerUid);
    }

    $user = zinesh_find_user_by_uid($uid) ?? $user;
    zinesh_json_response([
        'ok' => true,
        'message' => 'Eşleşme tamam. Para emanet kasasında kilitlendi.',
        'wallet' => zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
        'job' => zinesh_escrow_job_public_row($job, $uid),
        'campaignTask' => $agreementTask,
    ]);
}

if ($action === 'escrow_jobs_list') {
    $user = zinesh_require_auth($input);
    $uid = (string)$user['uid'];
    $email = (string)($user['email'] ?? '');
    $jobs = zinesh_escrow_trust_jobs_for_user($uid, $email);
    $stats = zinesh_escrow_trust_stats_for_user($uid, $email);
    $trustScore = zinesh_recalc_trust_score($uid, $email);
    zinesh_json_response([
        'ok' => true,
        'jobs' => $jobs,
        'stats' => $stats,
        'trustScore' => $trustScore,
    ]);
}

if ($action === 'escrow_job_cancel') {
    $user = zinesh_require_auth($input);
    $jobId = trim((string)($input['jobId'] ?? ''));
    if ($jobId === '') {
        zinesh_json_response(['message' => 'İş kimliği gerekli.'], 400);
    }
    $result = zinesh_escrow_job_request_cancel($user, $jobId);
    if (!$result['ok']) {
        zinesh_json_response(['message' => $result['message'] ?? 'İptal başarısız.'], 400);
    }
    $uid = (string)$user['uid'];
    $user = zinesh_find_user_by_uid($uid) ?? $user;
    zinesh_json_response([
        'ok' => true,
        'wallet' => $result['wallet'] ?? zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
        'job' => $result['job'] ?? null,
        'jobId' => $jobId,
        'message' => $result['message'] ?? null,
    ]);
}

if ($action === 'escrow_job_confirm_complete') {
    $user = zinesh_require_auth($input);
    $jobId = trim((string)($input['jobId'] ?? ''));
    if ($jobId === '') {
        zinesh_json_response(['message' => 'İş kimliği gerekli.'], 400);
    }
    $result = zinesh_escrow_job_confirm_complete($user, $jobId);
    if (!$result['ok']) {
        zinesh_json_response(['message' => $result['message'] ?? 'Onay alınamadı.'], 400);
    }
    $uid = (string)$user['uid'];
    $user = zinesh_find_user_by_uid($uid) ?? $user;
    zinesh_json_response([
        'ok' => true,
        'wallet' => $result['wallet'] ?? zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
        'job' => $result['job'] ?? null,
        'message' => $result['message'] ?? null,
    ]);
}

if ($action === 'escrow_job_request_cancel') {
    $user = zinesh_require_auth($input);
    $jobId = trim((string)($input['jobId'] ?? ''));
    if ($jobId === '') {
        zinesh_json_response(['message' => 'İş kimliği gerekli.'], 400);
    }
    $result = zinesh_escrow_job_request_cancel($user, $jobId);
    if (!$result['ok']) {
        zinesh_json_response(['message' => $result['message'] ?? 'İptal talebi alınamadı.'], 400);
    }
    $uid = (string)$user['uid'];
    $user = zinesh_find_user_by_uid($uid) ?? $user;
    zinesh_json_response([
        'ok' => true,
        'wallet' => $result['wallet'] ?? zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
        'job' => $result['job'] ?? null,
        'message' => $result['message'] ?? null,
    ]);
}

if ($action === 'escrow_lock') {
    $user = zinesh_require_auth($input);
    $amount = (float)($input['amount'] ?? 0);
    $reason = trim((string)($input['reason'] ?? 'escrow'));
    if ($amount <= 0) {
        zinesh_json_response(['message' => 'Geçersiz tutar.'], 400);
    }

    $uid = $user['uid'];
    $useTl = zinesh_escrow_uses_tl();
    $user = zinesh_update_user($uid, function (&$u) use ($amount, $useTl) {
        zinesh_ensure_wallet_fields($u);
        if ($useTl) {
            $available = round((float)$u['usdtBalance'] - (float)$u['escrowBalance'], 2);
            if ($available + 1e-9 < $amount) {
                zinesh_json_response(['message' => 'Yetersiz TL bakiyesi. Önce site cüzdanına para yatırın.'], 400);
            }
        } else {
            $available = round((float)$u['fiziBalance'] - (float)$u['escrowBalance'], 6);
            if ($available + 1e-9 < $amount) {
                zinesh_json_response(['message' => 'Yetersiz FİZİ. Önce vitrinden veya ödülden FİZİ edinin.'], 400);
            }
        }
        $u['escrowBalance'] = round((float)$u['escrowBalance'] + $amount, $useTl ? 2 : 6);
    });

    require_once __DIR__ . '/campaign_lib.php';
    $agreementTask = zinesh_campaign_record_agreement($uid);

    zinesh_json_response([
        'ok' => true,
        'wallet' => zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
        'reason' => $reason,
        'campaignTask' => $agreementTask,
    ]);
}

if ($action === 'escrow_release') {
    if (zinesh_tl_mode_enabled()) {
        zinesh_json_response([
            'message' => 'TL modunda manuel escrow serbest bırakma kapalı. İş tamamlanınca veya iptal edilince bakiye otomatik güncellenir.',
        ], 403);
    }
    $user = zinesh_require_auth($input);
    $amount = (float)($input['amount'] ?? 0);
    if ($amount <= 0) {
        zinesh_json_response(['message' => 'Geçersiz tutar.'], 400);
    }

    $useTl = zinesh_escrow_uses_tl();
    $decimals = $useTl ? 2 : 6;
    $uid = $user['uid'];
    $user = zinesh_update_user($uid, function (&$u) use ($amount, $decimals) {
        zinesh_ensure_wallet_fields($u);
        if ($u['escrowBalance'] < $amount) {
            zinesh_json_response(['message' => 'Escrow tutarı yetersiz.'], 400);
        }
        $u['escrowBalance'] = round($u['escrowBalance'] - $amount, $decimals);
    });

    zinesh_json_response([
        'ok' => true,
        'wallet' => zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
    ]);
}

if ($action === 'escrow_pay_supplier') {
    zinesh_json_response(['message' => 'settle_escrow kullanın.'], 400);
}

if ($action === 'settle_escrow') {
    $buyer = zinesh_require_auth($input);
    $jobId = trim((string)($input['jobId'] ?? ''));
    if ($jobId !== '') {
        $result = zinesh_escrow_job_confirm_complete($buyer, $jobId);
        if (!$result['ok']) {
            zinesh_json_response(['message' => $result['message'] ?? 'Onay alınamadı.'], 400);
        }
        $buyer = zinesh_find_user_by_uid((string)$buyer['uid']) ?? $buyer;
        zinesh_json_response([
            'ok' => true,
            'wallet' => $result['wallet'] ?? zinesh_wallet_state($buyer),
            'user' => zinesh_public_user($buyer),
            'job' => $result['job'] ?? null,
            'message' => $result['message'] ?? null,
        ]);
    }
    zinesh_json_response(['message' => 'İş kimliği gerekli. Ödeme için her iki taraf da onaylamalı.'], 400);
}

if ($action === 'campaign_jury') {
    zinesh_rate_limit('auth', (int)($limits['auth'] ?? 30));
    $user = zinesh_require_auth($input);
    $uid = $user['uid'];
    $correct = (bool)($input['correct'] ?? true);
    $result = zinesh_campaign_record_jury_vote($uid, $correct);
    $user = zinesh_find_user_by_uid($uid);
    zinesh_json_response([
        'ok' => true,
        'campaignReward' => $result,
        'wallet' => zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
    ]);
}

if ($action === 'add_wallet') {
    $user = zinesh_require_auth($input);
    $name = trim((string)($input['name'] ?? ''));
    $network = strtolower(trim((string)($input['network'] ?? '')));
    $address = trim((string)($input['address'] ?? ''));

    if ($name === '' || $address === '') {
        zinesh_json_response(['message' => 'Cüzdan adı ve adres gerekli.'], 400);
    }
    $addrErr = zinesh_validate_withdraw_address($network, $address);
    if ($addrErr) {
        zinesh_json_response(['message' => $addrErr], 400);
    }

    $uid = $user['uid'];
    $user = zinesh_update_user($uid, function (&$u) use ($name, $network, $address) {
        zinesh_ensure_wallet_fields($u);
        $u['connectedWallets'][] = [
            'id' => 'w-' . bin2hex(random_bytes(4)),
            'name' => $name,
            'network' => $network,
            'address' => $address,
        ];
    });

    zinesh_json_response([
        'ok' => true,
        'wallet' => zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
    ]);
}

zinesh_json_response(['message' => 'Geçersiz işlem.'], 400);
