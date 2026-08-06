<?php
declare(strict_types=1);

/**
 * Tüm kullanıcıları ve kampanya dağıtımını sıfırla — CLI:
 * php setup_reset_users.php [--confirm]
 */
require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/campaign_lib.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (!in_array('--confirm', $argv ?? [], true)) {
    fwrite(STDERR, "Kullanım: php setup_reset_users.php --confirm\n");
    exit(1);
}

zinesh_json_write('users.json', []);
zinesh_json_write('sessions.json', []);
zinesh_json_write('founding_campaign.json', zinesh_campaign_default_state());

echo "users.json sıfırlandı\n";
echo "sessions.json sıfırlandı\n";
echo "founding_campaign.json sıfırlandı — havuz: " . zinesh_campaign_default_state()['poolTotal'] . " FİZİ\n";
echo "Tamam.\n";
