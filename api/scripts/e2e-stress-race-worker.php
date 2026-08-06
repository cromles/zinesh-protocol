<?php
declare(strict_types=1);

/** Paralel race worker — founding_agreement tamamlar. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$uid = trim((string)($argv[1] ?? ''));
if ($uid === '') {
    fwrite(STDERR, "uid required\n");
    exit(2);
}

$apiDir = dirname(__DIR__);
require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/campaign_lib.php';

$agr = zinesh_campaign_record_agreement($uid);
$slot = zinesh_campaign_try_consume_founder_slot($uid);
$user = zinesh_find_user_by_uid($uid);

$out = [
    'uid' => $uid,
    'agreement' => $agr,
    'slot' => $slot,
    'foundingSlotConsumed' => !empty($user['foundingSlotConsumed']),
    'campaignEarned' => (float)(zinesh_campaign_user_progress($user ?? [])['fiziEarnedFromCampaign'] ?? 0),
    'slotsUsed' => (int)(zinesh_json_read('founding_campaign.json')['slotsUsed'] ?? 0),
];

$outFile = zinesh_data_path('stress-race-' . $uid . '.json');
file_put_contents($outFile, json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
