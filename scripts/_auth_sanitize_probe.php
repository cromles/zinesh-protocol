<?php
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/campaign_lib.php';
require_once '/www/wwwroot/zinesh.com/api/email_lib.php';
require_once '/www/wwwroot/zinesh.com/api/oauth_lib.php';
require_once '/www/wwwroot/zinesh.com/api/totp_lib.php';

$cfg = zinesh_config();
echo 'session_bind_fingerprint=' . (!empty($cfg['session_bind_fingerprint']) ? '1' : '0') . PHP_EOL;
echo 'oauth_public=' . json_encode(zinesh_google_oauth_public_config(), JSON_UNESCAPED_UNICODE) . PHP_EOL;

$uids = [
    '1b9b932370787e42f66cddc693a1c14a',
    'd86ae103e518b11af09f2c5233b611fa',
    '3d92373bde77ef56b7c390e586a31d82',
];
$users = zinesh_load_users();
foreach ($users as $row) {
    $uid = (string)($row['uid'] ?? '');
    if (!in_array($uid, $uids, true)) {
        continue;
    }
    $email = (string)($row['email'] ?? '');
    echo 'user=' . substr($email, 0, 3) . '*** uid=' . $uid
        . ' providers=' . json_encode($row['authProviders'] ?? [])
        . ' hasPass=' . (!empty($row['passwordHash']) ? '1' : '0')
        . ' founder=' . (zinesh_is_founder($row) ? '1' : '0')
        . PHP_EOL;
    try {
        zinesh_campaign_ensure_user_fields($row);
        $progress = zinesh_campaign_user_progress($row);
        $pub = zinesh_email_sanitize_public_user(array_merge($row, [
            'sessionToken' => 'x',
            'campaign' => $progress,
            'isFounder' => zinesh_is_founder($row),
        ]));
        $json = json_encode(['ok' => true, 'user' => $pub], JSON_UNESCAPED_UNICODE);
        echo 'sanitize_ok json_len=' . strlen((string)$json) . ' json_err=' . json_last_error_msg() . PHP_EOL;
    } catch (Throwable $e) {
        echo 'FAIL ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    }
}
