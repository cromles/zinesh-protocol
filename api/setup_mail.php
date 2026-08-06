<?php
declare(strict_types=1);

/**
 * SMTP kurulum — CLI:
 * php setup_mail.php --host smtp-relay.brevo.com --port 587 --user EMAIL --pass SMTP_KEY --from noreply@zinesh.com [--test alici@ornek.com]
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
    chmod($path, 0640);
    echo "SMTP devre dışı bırakıldı.\n";
    exit(0);
}

$host = trim((string)($opts['host'] ?? ''));
$user = trim((string)($opts['user'] ?? ''));
$pass = (string)($opts['pass'] ?? '');
$from = trim((string)($opts['from'] ?? 'noreply@zinesh.com'));
if (preg_match('/@(gmail|googlemail|yahoo|hotmail|outlook)\./i', $from)) {
    fwrite(STDERR, "Uyarı: {$from} freemail — gönderen noreply@zinesh.com olarak ayarlanıyor.\n");
    $from = 'noreply@zinesh.com';
}
$port = (int)($opts['port'] ?? 587);
$encryption = strtolower(trim((string)($opts['encryption'] ?? 'tls')));

if ($host === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "Kullanım:\n");
    fwrite(STDERR, "  php setup_mail.php --host SMTP_HOST --port 587 --user SMTP_USER --pass SMTP_PASS --from noreply@zinesh.com [--test email@test.com]\n");
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
if ($replyTo !== '') {
    $existing['mail_reply_to'] = $replyTo;
}
$apiKey = trim((string)($opts['api-key'] ?? ''));
if ($apiKey !== '') {
    $existing['brevo_api_key'] = $apiKey;
}

file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
chmod($path, 0640);

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
