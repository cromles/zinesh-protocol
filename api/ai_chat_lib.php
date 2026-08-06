<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/withdraw_executor.php';
require_once __DIR__ . '/campaign_lib.php';
require_once __DIR__ . '/founder_lib.php';
require_once __DIR__ . '/founder_health_lib.php';
require_once __DIR__ . '/early_access_lib.php';
require_once __DIR__ . '/tl_mode_lib.php';

function zinesh_ai_guided_actions(): array {
    return [
        'depositUsdt' => [
            'title' => 'USDT yükleme',
            'steps' => [
                'Giriş yap ve konsola geç.',
                'Sol menüden Ana Sayfam (Cüzdanım) bölümüne git.',
                'Yatır sekmesini seç.',
                'Ağ seç (TRON, Arbitrum, Ethereum veya Solana).',
                'Ekrandaki kasa adresine USDT gönder.',
                'İşlem hash\'ini gir veya otomatik eşleşmeyi bekle.',
            ],
        ],
        'buyFizi' => [
            'title' => 'FİZİ alma',
            'steps' => [
                'Konsolda Cüzdanım bölümüne git.',
                'Takas sekmesini aç.',
                'FİZİ Al modunu seç.',
                'USDT miktarını gir.',
                'Onayla — komisyon düşülür, FİZİ bakiyene yazılır.',
            ],
        ],
        'sellFizi' => [
            'title' => 'FİZİ satma',
            'steps' => [
                'Konsolda Cüzdanım → Takas → FİZİ Sat.',
                'Erken erişimde: en az 1 USDT yatırım + 3 doğrulanmış referans gerekir.',
                'Kampanya FİZİ\'si şartlar sağlanana kadar satılamayabilir; satılabilir bakiye ayrı gösterilir.',
                'Escrow\'da kilitli USDT satın alma için kullanılamaz.',
                'Minimum takas tutarı erken erişim kurallarına bağlıdır.',
            ],
        ],
    ];
}

/** @return array{sessionToken:string} */
function zinesh_ai_auth_input(array $body): array {
    $token = trim((string)($body['sessionToken'] ?? ''));

    if ($token === '') {
        $token = trim((string)($_SERVER['HTTP_X_SESSION_TOKEN'] ?? ''));
    }

    if ($token === '') {
        $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
            $token = trim($m[1]);
        }
    }

    if ($token === '' && !empty($_COOKIE['zinesh_session'])) {
        $token = trim((string)$_COOKIE['zinesh_session']);
    }

    return ['sessionToken' => $token];
}

/** @return array<string,mixed>|null */
function zinesh_ai_resolve_user(array $input): ?array {
    try {
        $user = zinesh_founder_health_resolve_user($input);
        if (!$user) {
            return null;
        }
        $uid = trim((string)($user['uid'] ?? ''));
        if ($uid === '') {
            return $user;
        }
        $fresh = zinesh_find_user_by_uid($uid);
        return $fresh ?: $user;
    } catch (Throwable) {
        return null;
    }
}

function zinesh_ai_resolve_founder(array $input): bool {
    $user = zinesh_ai_resolve_user($input);
    return $user !== null && zinesh_is_founder($user);
}

function zinesh_ai_format_num(float $n, int $decimals = 2): string {
    return number_format($n, $decimals, ',', '.');
}

/** @return array<string,mixed> */
function zinesh_ai_protocol_context(): array {
    $economics = zinesh_fizi_economics();
    $cfg = zinesh_config();
    return [
        'currentPriceUsdt' => round((float)($economics['price'] ?? 0), 8),
        'swapFeeRate' => (float)($cfg['swap_fee_rate'] ?? 0.005),
        'minDepositUsdt' => (float)($cfg['min_deposit_usdt'] ?? 10),
        'economicSupplyFizi' => (float)($economics['economicSupplyFizi'] ?? 0),
        'treasuryUsdt' => round((float)($economics['treasuryUsdt'] ?? 0), 2),
        'buyFormula' => 'fiziAlinan = (usdt - usdt * swapFeeRate) / currentPriceUsdt',
    ];
}

/** @return array<string,mixed> */
function zinesh_ai_sell_diagnosis(array $user): array {
    zinesh_ensure_wallet_fields($user);
    $sellable = zinesh_sellable_fizi($user);
    $gate = zinesh_early_access_sell_status($user);
    $purchased = zinesh_purchased_fizi($user);
    $campaign = zinesh_campaign_fizi_held($user);
    $totalFizi = round(max(0.0, (float)($user['fiziBalance'] ?? 0)), 6);
    $escrow = round(max(0.0, (float)($user['escrowBalance'] ?? 0)), 6);
    $liquidity = zinesh_liquidity_stats();

    /** @var list<array{code:string,message:string}> $reasons */
    $reasons = [];

    if (!$gate['allowed']) {
        $reasons[] = [
            'code' => 'early_access_gate',
            'message' => (string)($gate['reason'] ?? 'Erken erişim satış şartları eksik.'),
        ];
    }

    if ($totalFizi <= 0) {
        $reasons[] = [
            'code' => 'no_fizi',
            'message' => 'FİZİ bakiyeniz 0.',
        ];
    } elseif ($sellable <= 0 && $gate['allowed']) {
        if ($campaign > 0 && $purchased <= 0 && !zinesh_campaign_fizi_sellable()) {
            $reasons[] = [
                'code' => 'campaign_locked',
                'message' => sprintf(
                    'Toplam %s FİZİ\'nin tamamı kampanya ödülü (%s). Satılabilir satın alınmış FİZİ yok.',
                    zinesh_ai_format_num($totalFizi, 0),
                    zinesh_ai_format_num($campaign, 0)
                ),
            ];
        } else {
            $reasons[] = [
                'code' => 'not_sellable',
                'message' => 'Satılabilir FİZİ bakiyeniz 0.',
            ];
        }
    }

    if ($escrow > 0) {
        $reasons[] = [
            'code' => 'escrow_locked_usdt',
            'message' => sprintf(
                '%s USDT escrow\'da kilitli — FİZİ alımında kullanılamaz (kullanılabilir USDT daha düşük).',
                zinesh_ai_format_num($escrow, 2)
            ),
        ];
    }

    if ($sellable > 0 && $liquidity['availableUsdt'] <= 0) {
        $reasons[] = [
            'code' => 'liquidity',
            'message' => 'Kasa likiditesi yetersiz; satış geçici olarak kapalı olabilir.',
        ];
    }

    $primary = $reasons[0]['message'] ?? null;
    if ($sellable > 0 && $gate['allowed']) {
        $primary = null;
    }

    return [
        'canSell' => $sellable > 0 && !empty($gate['allowed']),
        'sellableFizi' => $sellable,
        'primaryReason' => $primary,
        'reasons' => $reasons,
        'balances' => [
            'fiziBalance' => $totalFizi,
            'sellableFiziBalance' => $sellable,
            'purchasedFiziBalance' => $purchased,
            'campaignFiziBalance' => $campaign,
            'escrowBalance' => $escrow,
            'availableUsdt' => round(max(0.0, (float)($user['usdtBalance'] ?? 0) - $escrow), 6),
        ],
        'earlyAccessSell' => $gate,
    ];
}

/** @return array<string,mixed> */
function zinesh_ai_wallet_context(?array $user): array {
    if ($user === null) {
        return ['loggedIn' => false];
    }

    zinesh_ensure_wallet_fields($user);
    $state = zinesh_wallet_state($user);
    $diagnosis = zinesh_ai_sell_diagnosis($user);
    $displayName = trim((string)($user['name'] ?? ''));
    if ($displayName !== '') {
        $displayName = preg_split('/\s+/', $displayName)[0] ?? $displayName;
    }

    return [
        'loggedIn' => true,
        'displayName' => $displayName !== '' ? $displayName : 'Kullanıcı',
        'trustScore' => (float)($user['trustScore'] ?? 50),
        'emailVerified' => !empty($user['emailVerified']),
        'fiziBalance' => round((float)($state['fiziBalance'] ?? 0), 6),
        'usdtBalance' => round((float)($state['usdtBalance'] ?? 0), 6),
        'availableUsdt' => round((float)($state['availableUsdt'] ?? 0), 6),
        'sellableFiziBalance' => round((float)($state['sellableFiziBalance'] ?? 0), 6),
        'campaignFiziBalance' => round((float)($state['campaignFiziBalance'] ?? 0), 6),
        'purchasedFiziBalance' => round((float)($state['purchasedFiziBalance'] ?? 0), 6),
        'escrowBalance' => round((float)($state['escrowBalance'] ?? 0), 6),
        'sellBlockReason' => $state['sellBlockReason'] ?? null,
        'earlyAccessSell' => $state['earlyAccessSell'] ?? null,
        'sellDiagnosis' => $diagnosis,
    ];
}

/** @return array<string,mixed> */
function zinesh_ai_calc_fizi_from_usdt(float $usdt): array {
    return [
        'inputUsdt' => round($usdt, 2),
        'tlMode' => zinesh_tl_mode_enabled(),
        'message' => 'TL emanet modunda FIZI takası yoktur.',
    ];
}

/** @return array<string,mixed> */
function zinesh_ai_rd_context(): array {
    return ['tlMode' => zinesh_tl_mode_enabled()];
}

/** @return array<string,mixed> */
function zinesh_ai_founder_health_context(): array {
    $health = zinesh_founder_system_health(true);
    $server = is_array($health['server'] ?? null) ? $health['server'] : [];
    $backup = is_array($health['backup'] ?? null) ? $health['backup'] : [];
    $dr = is_array($health['disasterRecovery'] ?? null) ? $health['disasterRecovery'] : [];
    $overall = is_array($health['overall'] ?? null) ? $health['overall'] : [];
    $inv = zinesh_ai_rd_context();

    return [
        'lastBackup' => $backup['lastSuccessLabel'] ?? $backup['display'] ?? null,
        'backupDisplay' => $backup['display'] ?? null,
        'googleDriveConnected' => !empty($dr['connected']),
        'googleDriveLabel' => $dr['label'] ?? null,
        'diskDisplay' => $server['diskDisplay'] ?? null,
        'memoryDisplay' => $server['memoryDisplay'] ?? null,
        'overallStatus' => $overall['statusLabel'] ?? null,
        'passedChecks' => $overall['passedChecks'] ?? null,
        'totalChecks' => $overall['totalChecks'] ?? null,
        'invariantStatus' => $inv['invariantStatus'] ?? null,
    ];
}

/**
 * @return list<array{label:string,action:string}>
 */
function zinesh_ai_default_suggestions(bool $loggedIn, bool $isFounder, string $topic = 'general'): array {
    $suggestions = [];

    if ($topic === 'wallet' || $topic === 'buy') {
        if ($loggedIn) {
            $suggestions[] = ['label' => 'FİZİ satın alma ekranını aç', 'action' => 'open_fizi_buy'];
        }
    }
    if ($topic === 'wallet' || $topic === 'sell') {
        if ($loggedIn) {
            $suggestions[] = ['label' => 'FİZİ satış ekranını aç', 'action' => 'open_fizi_sell'];
        }
    }
    if ($topic === 'deposit' || $topic === 'wallet' || $topic === 'buy') {
        if ($loggedIn) {
            $suggestions[] = ['label' => 'USDT yükleme sayfasına git', 'action' => 'open_deposit'];
        } else {
            $suggestions[] = ['label' => 'Kayıt ol ve konsola geç', 'action' => 'open_login'];
        }
    }
    if ($isFounder && ($topic === 'founder' || $topic === 'general')) {
        $suggestions[] = ['label' => 'Kurucu panelini görüntüle', 'action' => 'open_founder_panel'];
    }
    if ($suggestions === []) {
        $suggestions[] = ['label' => 'Konsola git', 'action' => 'open_dashboard'];
    }

    return array_slice($suggestions, 0, 3);
}

function zinesh_ai_normalize_question(string $q): string {
    $q = trim($q);
    $q = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $q = str_replace(['ı', 'İ', 'ğ', 'ü', 'ş', 'ö', 'ç'], ['i', 'i', 'g', 'u', 's', 'o', 'c'], $q);
    return $q;
}

/**
 * Deterministic answers for critical live-data questions.
 *
 * @return array{reply:string,suggestions:list<array{label:string,action:string}>}|null
 */
function zinesh_ai_try_direct_answer(string $question, ?array $user, bool $isFounder): ?array {
    $q = zinesh_ai_normalize_question($question);
    $loggedIn = $user !== null;
    $wallet = zinesh_ai_wallet_context($user);

    if (preg_match('/kac\s+fizi|fizi.*(m\s*var|im\s*var|bakiy)/u', $q)) {
        if (!$loggedIn) {
            return [
                'reply' => 'Kişisel FİZİ bakiyenizi görmek için giriş yapıp konsola geçmeniz gerekir.',
                'suggestions' => zinesh_ai_default_suggestions(false, false, 'wallet'),
            ];
        }
        $bal = (float)($wallet['fiziBalance'] ?? 0);
        $sell = (float)($wallet['sellableFiziBalance'] ?? 0);
        return [
            'reply' => sprintf(
                'FİZİ bakiyeniz: %s FİZİ. Satılabilir: %s FİZİ.',
                zinesh_ai_format_num($bal, 2),
                zinesh_ai_format_num($sell, 2)
            ),
            'suggestions' => zinesh_ai_default_suggestions(true, $isFounder, 'wallet'),
        ];
    }

    if (preg_match('/kac\s+usdt|usdt.*(m\s*var|im\s*var|bakiy)/u', $q)) {
        if (!$loggedIn) {
            return [
                'reply' => 'USDT bakiyeniz için giriş yapmanız gerekir.',
                'suggestions' => zinesh_ai_default_suggestions(false, false, 'wallet'),
            ];
        }
        $usdt = (float)($wallet['usdtBalance'] ?? 0);
        $avail = (float)($wallet['availableUsdt'] ?? 0);
        $escrow = (float)($wallet['escrowBalance'] ?? 0);
        $extra = $escrow > 0
            ? sprintf(' (%s USDT escrow\'da kilitli, kullanılabilir: %s USDT)', zinesh_ai_format_num($escrow, 2), zinesh_ai_format_num($avail, 2))
            : '';
        return [
            'reply' => sprintf('USDT bakiyeniz: %s USDT.%s', zinesh_ai_format_num($usdt, 2), $extra),
            'suggestions' => zinesh_ai_default_suggestions(true, $isFounder, 'wallet'),
        ];
    }

    if (preg_match('/satilabilir\s+fizi/u', $q)) {
        if (!$loggedIn) {
            return [
                'reply' => 'Satılabilir FİZİ miktarı için giriş yapın.',
                'suggestions' => zinesh_ai_default_suggestions(false, false, 'sell'),
            ];
        }
        $sell = (float)($wallet['sellableFiziBalance'] ?? 0);
        return [
            'reply' => sprintf('Satılabilir FİZİ miktarınız: %s FİZİ.', zinesh_ai_format_num($sell, 2)),
            'suggestions' => zinesh_ai_default_suggestions(true, $isFounder, 'sell'),
        ];
    }

    if (preg_match('/(\d+(?:[.,]\d+)?)\s*usdt/u', $q, $m) && preg_match('/fizi|kac|alabilir/u', $q)) {
        $amount = (float)str_replace(',', '.', $m[1]);
        if ($amount > 0) {
            $calc = zinesh_ai_calc_fizi_from_usdt($amount);
            $price = (float)$calc['currentPriceUsdt'];
            $fizi = (float)$calc['fiziReceived'];
            $fee = (float)$calc['feeUsdt'];
            $feeRatePct = round((float)$calc['feeRate'] * 100, 2);
            $extra = !empty($calc['partialDueToPool'])
                ? ' Satış havuzu sınırı nedeniyle kısmi alım gerekebilir.'
                : '';
            return [
                'reply' => sprintf(
                    'Güncel fiyat: $%s / FİZİ. %s USDT ile (%%%s komisyon = $%s) %s FİZİ alınır.%s',
                    zinesh_ai_format_num($price, 6),
                    zinesh_ai_format_num($amount, 2),
                    zinesh_ai_format_num($feeRatePct, 2),
                    zinesh_ai_format_num($fee, 2),
                    zinesh_ai_format_num($fizi, 2),
                    $extra
                ),
                'suggestions' => zinesh_ai_default_suggestions($loggedIn, $isFounder, 'buy'),
            ];
        }
    }

    if (preg_match('/neden.*(satam|satis.*(yapam|olmu)|satış)/u', $q)) {
        if (!$loggedIn) {
            return [
                'reply' => 'Satış engeli kişisel bakiyenize bağlıdır — giriş yapın.',
                'suggestions' => zinesh_ai_default_suggestions(false, false, 'sell'),
            ];
        }
        $diag = is_array($wallet['sellDiagnosis'] ?? null) ? $wallet['sellDiagnosis'] : zinesh_ai_sell_diagnosis($user);
        if (!empty($diag['canSell'])) {
            return [
                'reply' => sprintf(
                    'Satış engeliniz görünmüyor. Satılabilir: %s FİZİ. Takas → FİZİ Sat ekranından deneyin.',
                    zinesh_ai_format_num((float)($diag['sellableFizi'] ?? 0), 2)
                ),
                'suggestions' => zinesh_ai_default_suggestions(true, $isFounder, 'sell'),
            ];
        }
        $primary = (string)($diag['primaryReason'] ?? 'Satılabilir FİZİ bakiyeniz yok.');
        $bal = is_array($diag['balances'] ?? null) ? $diag['balances'] : [];
        $detail = sprintf(
            'Kampanya FİZİ: %s · Satın alınmış: %s · Escrow USDT: %s.',
            zinesh_ai_format_num((float)($bal['campaignFiziBalance'] ?? 0), 0),
            zinesh_ai_format_num((float)($bal['purchasedFiziBalance'] ?? 0), 0),
            zinesh_ai_format_num((float)($bal['escrowBalance'] ?? 0), 2)
        );
        return [
            'reply' => $primary . ' ' . $detail,
            'suggestions' => zinesh_ai_default_suggestions(true, $isFounder, 'sell'),
        ];
    }

    if (preg_match('/son\s+yedek|yedek\s+ne\s+zaman/u', $q)) {
        if (!$isFounder) {
            return [
                'reply' => 'Son yedek zamanı yalnızca kurucu oturumunda görüntülenir.',
                'suggestions' => zinesh_ai_default_suggestions($loggedIn, false, 'founder'),
            ];
        }
        $health = zinesh_ai_founder_health_context();
        $last = $health['lastBackup'] ?? $health['backupDisplay'] ?? 'Bilinmiyor';
        return [
            'reply' => sprintf('Son başarılı yedek: %s (Europe/Istanbul).', (string)$last),
            'suggestions' => zinesh_ai_default_suggestions($loggedIn, true, 'founder'),
        ];
    }

    if (preg_match('/google\s+drive|gdrive/u', $q)) {
        if (!$isFounder) {
            return [
                'reply' => 'Google Drive bağlantı durumu kurucu panelinde izlenir.',
                'suggestions' => zinesh_ai_default_suggestions($loggedIn, false, 'founder'),
            ];
        }
        $health = zinesh_ai_founder_health_context();
        $connected = !empty($health['googleDriveConnected']);
        $label = (string)($health['googleDriveLabel'] ?? ($connected ? 'Bağlı' : 'Bağlı değil'));
        return [
            'reply' => sprintf('Google Drive: %s.', $label),
            'suggestions' => zinesh_ai_default_suggestions($loggedIn, true, 'founder'),
        ];
    }

    if (preg_match('/invariant/u', $q)) {
        if (!$isFounder) {
            return [
                'reply' => 'AR-GE invariant durumu kurucu oturumunda görüntülenir.',
                'suggestions' => zinesh_ai_default_suggestions($loggedIn, false, 'founder'),
            ];
        }
        $health = zinesh_ai_founder_health_context();
        $status = (string)($health['invariantStatus'] ?? 'Bilinmiyor');
        return [
            'reply' => sprintf('AR-GE invariant durumu: %s.', $status),
            'suggestions' => zinesh_ai_default_suggestions($loggedIn, true, 'founder'),
        ];
    }

    if (preg_match('/usdt.*(yukle|yatir|deposit|nasil)|nasil.*usdt.*(yukle|yatir|deposit)/u', $q)) {
        $steps = zinesh_ai_guided_actions()['depositUsdt']['steps'];
        return [
            'reply' => "USDT yükleme adımları:\n" . implode("\n", array_map(
                static fn($s, $i) => ($i + 1) . '. ' . $s,
                $steps,
                array_keys($steps)
            )),
            'suggestions' => zinesh_ai_default_suggestions($loggedIn, $isFounder, 'deposit'),
        ];
    }

    if (preg_match('/nasil.*fizi.*(al|satin)|fizi.*nasil.*(al|satin)/u', $q)) {
        $steps = zinesh_ai_guided_actions()['buyFizi']['steps'];
        return [
            'reply' => "FİZİ alma:\n" . implode("\n", array_map(
                static fn($s, $i) => ($i + 1) . '. ' . $s,
                $steps,
                array_keys($steps)
            )),
            'suggestions' => zinesh_ai_default_suggestions($loggedIn, $isFounder, 'buy'),
        ];
    }

    if (preg_match('/nasil.*fizi.*sat|fizi.*nasil.*sat|neden.*fizi.*satam/u', $q)) {
        $steps = zinesh_ai_guided_actions()['sellFizi']['steps'];
        return [
            'reply' => "FİZİ satış kuralları:\n" . implode("\n", array_map(
                static fn($s, $i) => ($i + 1) . '. ' . $s,
                $steps,
                array_keys($steps)
            )),
            'suggestions' => zinesh_ai_default_suggestions($loggedIn, $isFounder, 'sell'),
        ];
    }

    if (preg_match('/trust\s*score|guven\s*puan|trustscore/u', $q)) {
        return [
            'reply' => 'Economic History Protocol kayıt tutar. Uzlaşmış escrow olayları deftere yazılır; Cooperation Research deneysel gözlemdir. Konsolda Economic History sekmesine bakın.',
            'suggestions' => zinesh_ai_default_suggestions($loggedIn, $isFounder, 'general'),
        ];
    }

    if (preg_match('/zinesh\s*(nedir|ne\b)|nedir.*zinesh|guven\s*protokol/u', $q)) {
        $protocol = zinesh_ai_protocol_context();
        $price = (float)($protocol['currentPriceUsdt'] ?? 0);
        return [
            'reply' => sprintf(
                'Zinesh, tanımadığınız kişilerle güvenli iş yapmanızı sağlayan bir protokoldür. Ödeme kasada bekler; anlaşmazlıkta hakemler devreye girer. Güncel FİZİ fiyatı: $%s / FİZİ.',
                zinesh_ai_format_num($price, 6)
            ),
            'suggestions' => zinesh_ai_default_suggestions($loggedIn, $isFounder, 'general'),
        ];
    }

    if (preg_match('/merhaba|selam|hey\b/u', $q)) {
        return [
            'reply' => 'Merhaba! Bakiye, USDT yatırma, FİZİ alım/satım veya protokol hakkında sorularınızı yazabilirsiniz.',
            'suggestions' => zinesh_ai_default_suggestions($loggedIn, $isFounder, 'general'),
        ];
    }

    return null;
}

/**
 * Canlı veriden yanıt yoksa kısa yönlendirme.
 *
 * @return array{reply:string,suggestions:list<array{label:string,action:string}>}
 */
function zinesh_ai_offline_fallback(string $question, ?array $user, bool $isFounder): array {
    $loggedIn = $user !== null;
    $q = zinesh_ai_normalize_question($question);

    if (preg_match('/usdt|yatir|yukle|deposit/u', $q)) {
        return [
            'reply' => 'USDT yatırma adımları için "Nasıl USDT yüklerim?" diye sorabilirsiniz veya aşağıdaki kısayolu kullanın.',
            'suggestions' => zinesh_ai_default_suggestions($loggedIn, $isFounder, 'deposit'),
        ];
    }
    if (preg_match('/fizi|takas|swap/u', $q)) {
        return [
            'reply' => 'FİZİ alım/satım ve bakiye sorularını canlı veriden yanıtlayabilirim. Örnek: "Kaç FİZİ\'m var?" veya "100 USDT ile kaç FİZİ alabilirim?"',
            'suggestions' => zinesh_ai_default_suggestions($loggedIn, $isFounder, 'buy'),
        ];
    }

    return [
        'reply' => 'Bu soruyu canlı veriden yanıtlayamadım. Bakiye, USDT yatırma ve emanet için aşağıdaki kısayolları kullanabilir veya sorunuzu daha net yazabilirsiniz.',
        'suggestions' => zinesh_ai_default_suggestions($loggedIn, $isFounder, 'general'),
    ];
}

function zinesh_ai_last_user_message(array $messages): string {
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (strtolower((string)($messages[$i]['role'] ?? '')) === 'user') {
            return trim((string)($messages[$i]['content'] ?? ''));
        }
    }
    return '';
}

/**
 * @param list<array{role:string,content:string}> $messages
 * @param array<string,mixed>|null $user
 * @return array<string,mixed>
 */
function zinesh_ai_chat(array $messages, bool $isFounder, ?array $user): array {
    $loggedIn = $user !== null;
    $lastQuestion = zinesh_ai_last_user_message($messages);

    if ($lastQuestion === '') {
        return [
            'ok' => false,
            'message' => 'Mesaj gerekli.',
            'code' => 'empty_message',
        ];
    }

    $direct = zinesh_ai_try_direct_answer($lastQuestion, $user, $isFounder);
    if ($direct !== null) {
        return [
            'ok' => true,
            'reply' => $direct['reply'],
            'suggestions' => $direct['suggestions'],
            'source' => 'live',
            'isFounder' => $isFounder,
            'loggedIn' => $loggedIn,
        ];
    }

    $fallback = zinesh_ai_offline_fallback($lastQuestion, $user, $isFounder);
    return [
        'ok' => true,
        'reply' => $fallback['reply'],
        'suggestions' => $fallback['suggestions'],
        'source' => 'local',
        'isFounder' => $isFounder,
        'loggedIn' => $loggedIn,
    ];
}
