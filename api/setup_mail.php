<?php
declare(strict_types=1);

/**
 * SMTP kurulum — CLI:
 * php setup_mail.php --host smtp-relay.brevo.com --port 587 --user LOGIN --pass SMTP_KEY --from verified@email.com [--test alici@ornek.com]
 *
 * --from: Brevo'da doğrulanmış gönderen (noreply@zinesh.com yalnızca domain SPF/DKIM sonrası).
 */
require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/campaign_lib.php';
require_once __DIR__ . '/email_lib.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$opts = getopt('', ['host:', 'port:', 'user:', 'pass:', 'from:', 'reply-to:', 'api-key:', 'encryption:', 'test:', 'disable']);

$path = zinesh_server_secrets_path();
$existing = file_exists($path)
    ? json_decode((string)file_get_contents($path), true)
    : [];
if (!is_array($existing)) {
    $existing = [];
}

if (isset($opts['disable'])) {
    $existing['smtp'] = ['enabled' => false];
    file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    zinesh_secure_secrets_file($path);
    echo "SMTP devre dışı bırakıldı.\n";
    exit(0);
}

$host = trim((string)($opts['host'] ?? ''));
$user = trim((string)($opts['user'] ?? ''));
$pass = (string)($opts['pass'] ?? '');
$from = trim((string)($opts['from'] ?? ''));
if ($from === '' || zinesh_mail_is_unverified_sender($from)) {
    $verified = zinesh_mail_verified_sender_email();
    if ($from !== '' && $from !== $verified) {
        fwrite(STDERR, "Uyarı: {$from} Brevo'da doğrulanmamış — gönderen {$verified} olarak ayarlanıyor.\n");
    }
    $from = $verified;
}
$port = (int)($opts['port'] ?? 587);
$encryption = strtolower(trim((string)($opts['encryption'] ?? 'tls')));

if ($host === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "Kullanım:\n");
    fwrite(STDERR, "  php setup_mail.php --host SMTP_HOST --port 587 --user SMTP_USER --pass SMTP_PASS --from verified@email.com [--test email@test.com]\n");
    fwrite(STDERR, "  php setup_mail.php --disable\n");
    exit(1);
}

$existing['smtp'] = [
    'enabled' => true,
    'host' => $host,
    'port' => $port,
    'encryption' => in_array($encryption, ['tls', 'ssl', 'none'], true) ? $encryption : 'tls',
    'username' => $user,
    'password' => $pass,
    'timeout' => 20,
    'configuredAt' => date('c'),
];
$existing['mail_from'] = $from;
$replyTo = trim((string)($opts['reply-to'] ?? ''));
if ($replyTo === '') {
    $envReply = getenv('ZINESH_MAIL_REPLY_TO');
    if (is_string($envReply) && trim($envReply) !== '') {
        $replyTo = trim($envReply);
    }
}
if ($replyTo !== '') {
    $replyTo = zinesh_mail_normalize_reply_to($replyTo, $from);
    $existing['mail_reply_to'] = $replyTo;
}
$apiKey = trim((string)($opts['api-key'] ?? ''));
if ($apiKey !== '') {
    $existing['brevo_api_key'] = $apiKey;
}

file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
zinesh_secure_secrets_file($path);

echo "SMTP kaydedildi: {$host}:{$port} ({$encryption})\n";
echo "Gönderen: {$from}\n";

$testTo = trim((string)($opts['test'] ?? ''));
if ($testTo !== '') {
    $result = zinesh_smtp_test_send($testTo);
    if ($result['ok']) {
        echo "Test e-postası gönderildi: {$testTo}\n";
        exit(0);
    }
    fwrite(STDERR, 'Test başarısız: ' . ($result['error'] ?? 'bilinmeyen') . "\n");
    exit(2);
}

echo "Test için: php setup_mail.php --host ... --user ... --pass ... --from ... --test alici@email.com\n";
