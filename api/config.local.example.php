<?php
/** Sunucuya özel ayarlar — bu dosyayı VDS'de /www/wwwroot/zinesh.com/api/config.local.php olarak oluşturun */
return [
    'admin_secret' => 'zinesh-admin-2026',

    /*
     * SMTP (önerilen: Brevo / SendGrid)
     * Güvenli yol: php setup_mail.php ile server_secrets.json'a yazın (şifre repoda kalmasın).
     *
     * Brevo örneği:
     *   host: smtp-relay.brevo.com
     *   port: 587
     *   encryption: tls
     *   user: Brevo hesabınızdaki e-posta
     *   pass: Brevo SMTP anahtarı (SMTP & API → SMTP keys)
     */
    // 'mail' => [
    //     'smtp' => [
    //         'enabled' => true,
    //         'host' => 'smtp-relay.brevo.com',
    //         'port' => 587,
    //         'encryption' => 'tls',
    //         'username' => 'sizin@email.com',
    //         'password' => 'BREVO_SMTP_KEY',
    //     ],
    //     'from_email' => 'noreply@zinesh.com',
    // ],

    /** app.zinesh.com gibi ayrı origin'lerden API erişimi (config.local.php ile genişletilebilir) */
    // 'cors_allowed_origins' => [
    //     'https://app.zinesh.com',
    // ],

    /** Kurucu paneli — AR-GE Rezervi yönetimi */
    /**
     * Kurucu hesap(lar) — TOTP sıfırlama ve kurucu paneli için zorunlu (canlıda doldurun).
     * 'founder_emails' => ['kurucu@sirket.com'],
    // 'founder_uids' => ['abc123uid'],

    /**
     * Google ile giriş (Firebase Blaze / yetkili alan adı gerekmez)
     * https://console.cloud.google.com/apis/credentials?project=decisive-patrol-dszp9
     * → Create Credentials → OAuth client ID → Web application
     * Authorized redirect URI: https://www.zinesh.com/api/auth.php?action=google_callback
     */
    // 'google_oauth' => [
    //     'client_id' => 'XXXX.apps.googleusercontent.com',
    //     'client_secret' => 'GOCSPX-...',
    // ],

    /** Kripto USDT yatırma (TL modunda genelde kapalı) */
    // 'deposits_enabled' => true,

    /** TL havale — IBAN ve banka bilgilerinizi buraya yazın */
    // 'tl_havale' => [
    //     'iban' => 'TR00 0000 0000 0000 0000 0000 00',
    //     'account_holder' => 'Ad Soyad',
    //     'bank_name' => 'Banka Adı',
    //     'min_deposit_try' => 50,
    //     'auto_approve_founder' => true,
    // ],

    /**
     * Faz 2 — iyzico ile TL yatırma (sandbox: https://sandbox-merchant.iyzipay.com)
     * 'payment' => [
     *     'min_deposit_try' => 50,
     *     'max_deposit_try' => 50000,
     *     'iyzico' => [
     *         'sandbox' => true,
     *         'api_key' => 'sandbox-xxx',
     *         'secret_key' => 'sandbox-xxx',
     *     ],
     * ],
     */
];
