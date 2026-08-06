<?php
declare(strict_types=1);

/**
 * Node auth proxy → PHP köprüsü (stdin JSON → auth.php)
 */
$raw = file_get_contents('php://stdin');
if ($raw === false || trim($raw) === '') {
    http_response_code(400);
    echo json_encode(['message' => 'empty body'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_SERVER['REMOTE_ADDR'] = getenv('ZINESH_CLIENT_IP') ?: '127.0.0.1';

stream_wrapper_unregister('php');

final class ZineshPhpInputWrapper {
    private static string $body = '';
    private int $pos = 0;

    public static function setBody(string $body): void {
        self::$body = $body;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
        if ($path === 'php://input') {
            $this->pos = 0;
            return true;
        }
        return false;
    }

    public function stream_read(int $count): string {
        $chunk = substr(self::$body, $this->pos, $count);
        $this->pos += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool {
        return $this->pos >= strlen(self::$body);
    }

    public function stream_stat(): array {
        return [];
    }
}

stream_wrapper_register('php', ZineshPhpInputWrapper::class);
ZineshPhpInputWrapper::setBody($raw);

include __DIR__ . '/auth.php';
