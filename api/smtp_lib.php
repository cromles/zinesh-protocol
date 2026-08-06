<?php
declare(strict_types=1);

/**
 * Basit SMTP istemcisi (AUTH LOGIN + STARTTLS / SSL).
 * @return array{ok:bool, error:?string}
 */
function zinesh_smtp_send(
    array $smtp,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $subject,
    string $mimeBody,
    string $replyToEmail = ''
): array {
    $host = trim((string)($smtp['host'] ?? ''));
    $port = (int)($smtp['port'] ?? 587);
    $encryption = strtolower(trim((string)($smtp['encryption'] ?? 'tls')));
    $username = (string)($smtp['username'] ?? '');
    $password = (string)($smtp['password'] ?? '');
    $timeout = (int)($smtp['timeout'] ?? 20);

    if ($host === '' || $toEmail === '' || $fromEmail === '') {
        return ['ok' => false, 'error' => 'SMTP yapılandırması eksik.'];
    }

    $remote = $encryption === 'ssl'
        ? "ssl://{$host}:{$port}"
        : "tcp://{$host}:{$port}";

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return ['ok' => false, 'error' => "SMTP bağlantısı kurulamadı: {$errstr} ({$errno})"];
    }

    stream_set_timeout($fp, $timeout);

    try {
        $greet = zinesh_smtp_read($fp);
        if (!str_starts_with($greet, '220')) {
            throw new RuntimeException('SMTP karşılama hatası: ' . trim($greet));
        }

        $ehloHost = parse_url((string)(zinesh_mail_config()['site_url'] ?? 'https://zinesh.com'), PHP_URL_HOST) ?: 'zinesh.com';
        zinesh_smtp_cmd($fp, "EHLO {$ehloHost}");

        if ($encryption === 'tls') {
            zinesh_smtp_cmd($fp, 'STARTTLS');
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS başarısız.');
            }
            zinesh_smtp_cmd($fp, "EHLO {$ehloHost}");
        }

        if ($username !== '' && $password !== '') {
            zinesh_smtp_cmd($fp, 'AUTH LOGIN');
            zinesh_smtp_cmd($fp, base64_encode($username));
            zinesh_smtp_cmd($fp, base64_encode($password));
        }

        zinesh_smtp_cmd($fp, "MAIL FROM:<{$fromEmail}>");
        zinesh_smtp_cmd($fp, "RCPT TO:<{$toEmail}>");
        zinesh_smtp_cmd($fp, 'DATA');

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromHeader = sprintf('"%s" <%s>', addcslashes($fromName, '"\\'), $fromEmail);
        $message = "From: {$fromHeader}\r\n";
        $message .= "To: <{$toEmail}>\r\n";
        if ($replyToEmail !== '') {
            $message .= "Reply-To: <{$replyToEmail}>\r\n";
        }
        $message .= "Subject: {$encodedSubject}\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= $mimeBody;
        $message .= "\r\n.\r\n";

        fwrite($fp, $message);
        $dataResp = zinesh_smtp_read($fp);
        if (!str_starts_with($dataResp, '250')) {
            throw new RuntimeException('SMTP DATA hatası: ' . trim($dataResp));
        }

        zinesh_smtp_cmd($fp, 'QUIT');
        fclose($fp);
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        fclose($fp);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function zinesh_smtp_cmd($fp, string $cmd): string {
    fwrite($fp, $cmd . "\r\n");
    $resp = zinesh_smtp_read($fp);
    $code = (int)substr($resp, 0, 3);
    if ($code >= 400) {
        throw new RuntimeException("SMTP komut hatası [{$cmd}]: " . trim($resp));
    }
    return $resp;
}

function zinesh_smtp_read($fp): string {
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function zinesh_smtp_configured(): bool {
    $mail = zinesh_mail_config();
    $smtp = $mail['smtp'] ?? [];
    if (empty($smtp['enabled'])) {
        return false;
    }
    return trim((string)($smtp['host'] ?? '')) !== '';
}

function zinesh_smtp_test_send(string $toEmail): array {
    $cfg = zinesh_mail_config();
    $from = (string)$cfg['from_email'];
    $name = (string)$cfg['from_name'];
    $boundary = 'zinesh_test_' . bin2hex(random_bytes(4));
    $body = "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= '<p>Zinesh SMTP testi başarılı. ' . date('c') . '</p>';
    $result = zinesh_smtp_send($cfg['smtp'] ?? [], $from, $name, $toEmail, 'Zinesh SMTP Test', $body);
    if ($result['ok']) {
        zinesh_audit('smtp_test_ok', ['to' => $toEmail]);
    } else {
        zinesh_audit('smtp_test_fail', ['to' => $toEmail, 'error' => $result['error']]);
    }
    return $result;
}
