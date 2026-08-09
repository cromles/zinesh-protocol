<?php
declare(strict_types=1);

/**
 * Yerel demo API sunucusu:
 *   php -S localhost:8787 scripts/demo-api-router.php
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$apiRoot = dirname(__DIR__) . '/api';

if (str_starts_with($path, '/api/')) {
    $path = substr($path, 4);
}
if ($path === '' || $path === '/') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'service' => 'zinesh-demo-api',
        'hint' => 'Use /api/demo.php?action=status',
    ], JSON_UNESCAPED_UNICODE);
    return true;
}

$script = $apiRoot . $path;
if (is_file($script) && str_ends_with($script, '.php')) {
    chdir($apiRoot);
    putenv('ZINESH_DEMO_MODE=1');
    require $script;
    return true;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'message' => 'Not found', 'path' => $path]);
return true;
