<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/email_lib.php';

$uid = trim((string)($argv[1] ?? ''));
if ($uid === '' || !zinesh_find_user_by_uid($uid)) {
    fwrite(STDERR, "user_not_found\n");
    exit(1);
}

$issued = zinesh_email_issue_verification_code();
zinesh_update_user($uid, static function (array &$u) use ($issued) {
    $u['emailVerifyCode'] = $issued['hash'];
    $u['emailVerifyExpires'] = $issued['expires'];
});

echo $issued['plain'];
