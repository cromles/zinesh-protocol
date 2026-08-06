<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (!function_exists('zinesh_is_founder')) {
    function zinesh_is_founder(array $user): bool
    {
        $cfg = zinesh_config();
        $uid = (string)($user['uid'] ?? '');
        $email = strtolower(trim((string)($user['email'] ?? '')));

        $founderUids = $cfg['founder_uids'] ?? [];
        if (is_string($founderUids)) {
            $founderUids = array_filter(array_map('trim', explode(',', $founderUids)));
        }
        if ($uid !== '' && in_array($uid, $founderUids, true)) {
            return true;
        }

        $founderEmails = $cfg['founder_emails'] ?? [];
        if (is_string($founderEmails)) {
            $founderEmails = array_filter(array_map('trim', explode(',', $founderEmails)));
        }
        foreach ($founderEmails as $fe) {
            if ($email !== '' && $email === strtolower(trim((string)$fe))) {
                return true;
            }
        }

        return !empty($user['isFounder']);
    }
}
