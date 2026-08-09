<?php
/**
 * Zinesh kasa adresleri ve ağ sabitleri.
 * Sunucuda /www/server/zinesh-data/config.local.php ile API anahtarları eklenebilir.
 */

require_once __DIR__ . '/protocol_constants.php';
$__zineshProtocol = zinesh_protocol_constants();

return [
    'data_dir' => '/www/server/zinesh-data',

    /**
     * Kripto (USDT) yatırma — TL modunda varsayılan kapalı.
     * iyzico TL yatırma ayrı; kripto için config.local.php ile true yapın.
     */
    'deposits_enabled' => false,

    /**
     * TL havale yatırma — IBAN config.local.php içinde doldurulmalı.
     * Test: kurucu hesabında auto_approve_founder ile anında bakiye.
     */
    'tl_havale' => [
        'enabled' => true,
        'iban' => 'TR86 0001 0026 3777 7502 4250 04',
        'account_holder' => 'Yasin Karademir',
        'bank_name' => 'Ziraat Bankası',
        'min_deposit_try' => 10,
        'auto_approve_founder' => false,
    ],

    'treasury_legacy' => [
        'tron'     => 'TNNP6ehRJ9EYXddZkbsneJfMBQx7rdytRr',
        'arbitrum' => '0x06f7945E9D6e81110C998119012ea38B63B0b77c',
        'ethereum' => '0x06f7945E9D6e81110C998119012ea38B63B0b77c',
        'solana'   => 'FZVwrPoJ4qbwAmxWwscgmZgsEYcojD69j4L19V3QJCtQ',
    ],

    /** @deprecated zinesh_active_treasury() kullanın */
    'treasury' => [
        'tron'     => 'TNNP6ehRJ9EYXddZkbsneJfMBQx7rdytRr',
        'arbitrum' => '0x06f7945E9D6e81110C998119012ea38B63B0b77c',
        'ethereum' => '0x06f7945E9D6e81110C998119012ea38B63B0b77c',
        'solana'   => 'FZVwrPoJ4qbwAmxWwscgmZgsEYcojD69j4L19V3QJCtQ',
    ],

    /** @deprecated zinesh_arge_development_wallet_evm() / zinesh_active_treasury() kullanın */
    'development_wallet' => '0x06f7945E9D6e81110C998119012ea38B63B0b77c',

    'usdt_contracts' => [
        'tron'     => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
        'arbitrum' => '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9',
        'ethereum' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',
        'solana'   => 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB',
    ],

    'rpc' => [
        'tron'     => 'https://api.trongrid.io',
        'arbitrum' => 'https://arb1.arbitrum.io/rpc',
        'ethereum' => 'https://ethereum.publicnode.com',
        'solana'   => 'https://api.mainnet-beta.solana.com',
    ],

    'fizi_price_floor' => $__zineshProtocol['floor_price_usdt'],
    'swap_fee_rate' => 0.005,
    'min_deposit_usdt' => 10.0,
    'min_withdraw_usdt' => 5.0,

    // Opsiyonel: Arbiscan / Etherscan / TronGrid
    'arbiscan_api_key' => '',
    'etherscan_api_key' => '',
    'trongrid_api_key' => '',

    // Çekim yönetimi: /api/admin.php?key=...
    'admin_secret' => '',

    // Node.js yolu (otomatik çekim)
    'node_binary' => 'node',

    // Cron: /api/cron_withdraw.php (Authorization header veya POST)
    'cron_key' => '',

    // Günlük çekim limiti (kullanıcı başına USDT)
    'daily_withdraw_limit_usdt' => 5000.0,

    /** Hot wallet otomatik çekim üst sınırı — kasada bu tutarın üzerinde USDT varsa manuel onay gerekir */
    'hot_wallet_max_usdt' => 500.0,

    // Rate limit (istek/dakika, IP başına)
    'rate_limits' => [
        /** Tüm /api/*.php istekleri — DDoS'ta origin'i korur */
        'global_api' => 350,
        /** 429 sonrası bekleme (saniye); Retry-After başlığı */
        'retry_after' => 15,
        'auth' => 20,
        'deposit' => 10,
        'withdraw' => 5,
        'swap' => 30,
        'ai_chat' => 20,
        'notifications_read' => 120,
        'notifications_write' => 40,
    ],

    /** JSON dosya kilidi — yoğunlukta kısa retry sonra 503 */
    'file_lock' => [
        'retries' => 6,
        'retry_ms' => 30,
    ],

    /**
     * Oturum (sessions.json token) — hareketsizlik zaman aşımı (saniye).
     * 0 = kapalı (hareketsizlikte çıkış yok).
     */
    'session_idle_seconds' => 0,

    /** Token mutlak üst süre (saniye) — hareketsizlikten bağımsız; mobil için 30 gün */
    'session_max_seconds' => 60 * 60 * 24 * 30,

    /**
     * Demo modu — yalnızca staging / localhost (production'da false kalmalı).
     * true: demo.php + tek tık demo girişi aktif.
     */
    'demo_mode' => false,

    /** UA parmak izi — kapalı (heal ile zorlanır); oturum düşürmesin */
    'session_bind_fingerprint' => false,

    /** lastActivity yazım aralığı (saniye) — sessions.json kilit çatışmasını azaltır */
    'session_touch_interval' => 30,

    /** Periyodik token rotasyonu (saniye) — kurucu hesaplarda kapalı; diğerleri 12 saat */
    'session_rotate_seconds' => 60 * 60 * 12,

    /** FİZİ toplam arz — ProtocolConstants.TOTAL_SUPPLY */
    'fizi_total_supply' => $__zineshProtocol['total_supply'],

    /** AR-GE Rezervi — aylık açılım (dolaşım × %1, her ayın 21'i 19:00 Europe/Istanbul) */
    'rd_reserve_wallet_uid' => 'rd_reserve_wallet',
    'rd_reserve_max_fizi' => $__zineshProtocol['rd_reserve_max_fizi'],
    'rd_monthly_unlock_rate' => $__zineshProtocol['rd_monthly_unlock_rate'] ?? 0.01,
    'rd_monthly_unlock_day' => 21,
    'rd_monthly_unlock_hour' => 19,
    'rd_monthly_unlock_minute' => 0,
    'rd_monthly_unlock_timezone' => 'Europe/Istanbul',
    'rd_inaugural_unlock_year' => $__zineshProtocol['rd_inaugural_unlock_year'] ?? 2026,
    'rd_inaugural_unlock_month' => $__zineshProtocol['rd_inaugural_unlock_month'] ?? 6,

    /** Kampanya FIZI satilabilir mi — ProtocolConstants.CAMPAIGN_SELLABLE */
    'campaign_fizi_sellable' => $__zineshProtocol['campaign_fizi_sellable'],

    /** Kurucu paneli — sunucuda config.local.php ile e-posta/uid ekleyin */
    'founder_uids' => [],
    'founder_emails' => [],

    /** Erken erişim dönemi kuralları — admin panelinden early_access.json ile güncellenebilir */
    'early_access' => [
        'enabled' => true,
        'title' => 'Erken Erişim Dönemi',
        'max_users' => 500,
        'max_fizi_per_user' => 500,
        'min_swap_usdt' => 1.0,
        'sell_requires_deposit' => true,
        'sell_requires_referrals' => 3,
        'sale_requires_current_deposit' => false,
    ],

    /**
     * Erken Erişim Kampanyası — 500 kullanıcı · kişi başı max 500 platform FIZI (5×100)
     */
    'founding_campaign' => [
        'enabled' => true,
        'title' => 'Erken Erişim',
        'supply_percent' => 0.25,
        'pool_total' => 250000,
        'slots_total' => 500,
        'launch_generation' => '2026-07-06',
        'reward_signup' => 100,
        'reward_kyc' => 100,
        'reward_deposit_10' => 100,
        'reward_agreement' => 100,
        'reward_first_fizi_buy' => 0,
        'reward_first_job' => 0,
        'reward_referrals_3' => 100,
        'reward_first_jury' => 0,
        'reward_first_correct_jury' => 0,
        'referrals_required' => 3,
    ],

    'mail' => [
        // noreply@zinesh.com yalnızca Brevo'da domain doğrulandıktan sonra kullanılabilir.
        // email_lib.php doğrulanmamış adresi kurucu e-postasına düşürür.
        'from_email' => 'noreply@zinesh.com',
        'from_name' => 'Zinesh',
        'site_url' => 'https://www.zinesh.com',
        'smtp' => [
            'enabled' => false,
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
        ],
    ],

    /** app.zinesh.com gibi ayrı origin'lerden API erişimi */
    'cors_allowed_origins' => [
        'https://www.zinesh.com',
        'https://zinesh.com',
        'https://app.zinesh.com',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ],

    /** Frontend olay telemetrisi — /api/events.php */
    'events' => [
        'rate_limit_per_minute' => 60,
        'allowed' => [
            'app_loaded',
            'landing_view',
            'console_open',
            'register_success',
            'login_success',
            'deposit_success',
            'swap_success',
            'referral_landing',
        ],
    ],

    /** Google ile giriş — Firebase popup veya sunucu yönlendirmesi (ücretsiz, Blaze gerekmez) */
    'google_oauth' => [
        'enabled' => true,
        'firebase_web_api_key' => 'AIzaSyAZABFLej-na9bRcCI6-e3iwv0bA1tQkn4',
        'client_id' => '492794009757-8cqfdv9mb3kc1kg0kiqre9mtosm1inol.apps.googleusercontent.com',
        // client_secret yalnızca config.local.php — GIS akışında gerekmez
    ],
];
