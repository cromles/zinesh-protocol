<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/verification_lib.php';

const ZINESH_DEMO_EMPLOYER_UID = 'zinesh-demo-employer-v1';
const ZINESH_DEMO_WORKER_UID = 'zinesh-demo-worker-v1';
const ZINESH_DEMO_EMPLOYER_EMAIL = 'demo-employer@zinesh.local';
const ZINESH_DEMO_WORKER_EMAIL = 'demo-worker@zinesh.local';
const ZINESH_DEMO_EMPLOYER_TICKET = '90001';
const ZINESH_DEMO_WORKER_TICKET = '90002';
const ZINESH_DEMO_BALANCE_TRY = 50000.0;

/** Production dışında demo modu — www/app.zinesh.com'da asla açık değil. */
function zinesh_demo_mode_enabled(): bool
{
    $forced = getenv('ZINESH_DEMO_MODE');
    if ($forced === '1' || $forced === 'true') {
        return true;
    }
    if ($forced === '0' || $forced === 'false') {
        return false;
    }

    $cfg = zinesh_config();
    if (array_key_exists('demo_mode', $cfg)) {
        return (bool)$cfg['demo_mode'];
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (in_array($host, ['zinesh.com', 'www.zinesh.com', 'app.zinesh.com'], true)) {
        return false;
    }

    if (is_string(getenv('ZINESH_SIM_DATA_DIR')) && getenv('ZINESH_SIM_DATA_DIR') !== '') {
        return true;
    }

    $dataDir = str_replace('\\', '/', zinesh_resolve_data_dir());
    if (str_ends_with($dataDir, '/api/data')) {
        return true;
    }

    if (
        $host === ''
        || str_contains($host, 'localhost')
        || str_contains($host, '127.0.0.1')
        || str_contains($host, 'staging')
        || str_ends_with($host, '.local')
    ) {
        return true;
    }

    return false;
}

function zinesh_demo_deny_if_disabled(): void
{
    if (!zinesh_demo_mode_enabled()) {
        zinesh_json_response(['ok' => false, 'message' => 'Demo mode disabled.'], 404);
    }
}

/** @return list<string> */
function zinesh_demo_user_uids(): array
{
    return [ZINESH_DEMO_EMPLOYER_UID, ZINESH_DEMO_WORKER_UID];
}

function zinesh_demo_is_demo_uid(string $uid): bool
{
    return in_array($uid, zinesh_demo_user_uids(), true);
}

function zinesh_demo_record_error(string $message): void
{
    if (!zinesh_demo_mode_enabled()) {
        return;
    }
    zinesh_json_write('demo_last_error.json', [
        'message' => $message,
        'at' => date('c'),
    ]);
}

function zinesh_demo_last_error(): ?string
{
    $row = zinesh_json_read('demo_last_error.json');
    if (!is_array($row)) {
        return null;
    }
    $msg = trim((string)($row['message'] ?? ''));
    return $msg !== '' ? $msg : null;
}

function zinesh_demo_clear_error(): void
{
    zinesh_json_write('demo_last_error.json', ['message' => '', 'at' => date('c')]);
}

/** @return array<string,mixed> */
function zinesh_demo_user_template(string $role): array
{
    $isEmployer = $role === 'employer';
    return [
        'uid' => $isEmployer ? ZINESH_DEMO_EMPLOYER_UID : ZINESH_DEMO_WORKER_UID,
        'email' => $isEmployer ? ZINESH_DEMO_EMPLOYER_EMAIL : ZINESH_DEMO_WORKER_EMAIL,
        'name' => $isEmployer ? 'Demo Employer' : 'Demo Worker',
        'role' => 'real',
        'ticketNumber' => $isEmployer ? ZINESH_DEMO_EMPLOYER_TICKET : ZINESH_DEMO_WORKER_TICKET,
        'trustScore' => 50.0,
        'emailVerified' => true,
        'emailVerifiedAt' => date('c'),
        'phoneVerified' => true,
        'kycStatus' => 'approved',
        'kycVerified' => true,
        'usdtBalance' => ZINESH_DEMO_BALANCE_TRY,
        'fiziBalance' => 0.0,
        'campaignFiziBalance' => 0.0,
        'platformFiziBalance' => 0.0,
        'escrowBalance' => 0.0,
        'walletAddress' => '',
        'connectedWallets' => [],
        'createdAt' => date('c'),
        'isDemoUser' => true,
    ];
}

function zinesh_demo_ensure_users(): void
{
    zinesh_ensure_core_data_files();
    foreach (['employer', 'worker'] as $role) {
        $template = zinesh_demo_user_template($role);
        $uid = (string)$template['uid'];
        $existing = zinesh_find_user_by_uid($uid);
        if ($existing === null) {
            zinesh_json_atomic('users.json', static function (array &$users) use ($template, $uid) {
                foreach ($users as $u) {
                    if ((string)($u['uid'] ?? '') === $uid) {
                        return false;
                    }
                }
                $users[] = $template;
                return true;
            });
            continue;
        }
        zinesh_update_user($uid, static function (array &$u) use ($template) {
            $u['email'] = $template['email'];
            $u['name'] = $template['name'];
            $u['ticketNumber'] = $template['ticketNumber'];
            $u['emailVerified'] = true;
            $u['phoneVerified'] = true;
            $u['kycStatus'] = 'approved';
            $u['kycVerified'] = true;
            $u['isDemoUser'] = true;
            zinesh_ensure_wallet_fields($u);
            if ((float)$u['escrowBalance'] < 0.01) {
                $u['usdtBalance'] = ZINESH_DEMO_BALANCE_TRY;
            }
        });
    }
}

function zinesh_demo_reset_escrow_state(): void
{
    zinesh_demo_ensure_users();

    $demoUids = zinesh_demo_user_uids();
    $removedRoomIds = [];

    zinesh_json_atomic('escrow_rooms.json', static function (array &$rows) use ($demoUids, &$removedRoomIds) {
        $kept = [];
        foreach ($rows as $row) {
            $employer = (string)($row['employerUid'] ?? '');
            $worker = (string)($row['workerUid'] ?? '');
            if (in_array($employer, $demoUids, true) || in_array($worker, $demoUids, true)) {
                $removedRoomIds[] = (string)($row['id'] ?? '');
                continue;
            }
            $kept[] = $row;
        }
        $rows = array_values($kept);
        return true;
    });

    if ($removedRoomIds !== []) {
        zinesh_json_atomic('escrow_room_messages.json', static function (array &$messages) use ($removedRoomIds) {
            $messages = array_values(array_filter(
                $messages,
                static fn(array $m): bool => !in_array((string)($m['roomId'] ?? ''), $removedRoomIds, true)
            ));
            return true;
        });
    }

    foreach ($demoUids as $uid) {
        zinesh_update_user($uid, static function (array &$u) {
            zinesh_ensure_wallet_fields($u);
            $u['usdtBalance'] = ZINESH_DEMO_BALANCE_TRY;
            $u['escrowBalance'] = 0.0;
        });
    }
}

/** Demo oturumu — production email/campaign sanitize zincirine girmez. */
function zinesh_demo_public_user(array $user, string $sessionToken): array
{
    zinesh_ensure_wallet_fields($user);
    return [
        'uid' => (string)($user['uid'] ?? ''),
        'name' => (string)($user['name'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'role' => (string)($user['role'] ?? 'real'),
        'ticketNumber' => (string)($user['ticketNumber'] ?? ''),
        'trustScore' => (float)($user['trustScore'] ?? 50),
        'usdtBalance' => (float)($user['usdtBalance'] ?? 0),
        'escrowBalance' => (float)($user['escrowBalance'] ?? 0),
        'fiziBalance' => (float)($user['fiziBalance'] ?? 0),
        'walletAddress' => (string)($user['walletAddress'] ?? ''),
        'sessionToken' => $sessionToken,
        'emailVerified' => true,
        'phoneVerified' => true,
        'kycVerified' => true,
        'kycStatus' => 'approved',
        'contractVerificationReady' => true,
        'isFounder' => false,
        'isDemoUser' => true,
    ];
}

/** @return array{ok:bool,message?:string,user?:array,wallet?:array} */
function zinesh_demo_login(string $role): array
{
    if (!in_array($role, ['employer', 'worker'], true)) {
        return ['ok' => false, 'message' => 'Geçersiz demo rolü.'];
    }

    try {
        zinesh_demo_ensure_users();
        $uid = $role === 'employer' ? ZINESH_DEMO_EMPLOYER_UID : ZINESH_DEMO_WORKER_UID;
        $user = zinesh_find_user_by_uid($uid);
        if ($user === null) {
            zinesh_demo_record_error('Demo kullanıcı oluşturulamadı.');
            return ['ok' => false, 'message' => 'Demo kullanıcı oluşturulamadı.'];
        }

        zinesh_ensure_wallet_fields($user);
        $token = zinesh_create_session($uid);
        if (function_exists('zinesh_emit_session_cookie')) {
            zinesh_emit_session_cookie($token);
        }

        $public = zinesh_demo_public_user($user, $token);

        zinesh_demo_clear_error();

        return [
            'ok' => true,
            'user' => $public,
            'wallet' => zinesh_wallet_state($user),
            'role' => $role,
            'ticketNumber' => $role === 'employer' ? ZINESH_DEMO_EMPLOYER_TICKET : ZINESH_DEMO_WORKER_TICKET,
        ];
    } catch (Throwable $e) {
        zinesh_demo_record_error($e->getMessage());
        return ['ok' => false, 'message' => 'Demo girişi başarısız: ' . $e->getMessage()];
    }
}

/** @return array<string,mixed> */
function zinesh_demo_public_status(): array
{
    return [
        'ok' => true,
        'demoEnabled' => zinesh_demo_mode_enabled(),
        'employerTicket' => ZINESH_DEMO_EMPLOYER_TICKET,
        'workerTicket' => ZINESH_DEMO_WORKER_TICKET,
        'balanceTry' => ZINESH_DEMO_BALANCE_TRY,
    ];
}

/** @return array<string,mixed> */
function zinesh_demo_health_report(): array
{
    $checks = [
        'wallet' => ['ok' => false, 'detail' => ''],
        'room' => ['ok' => false, 'detail' => ''],
        'status' => ['ok' => false, 'detail' => ''],
        'json' => ['ok' => false, 'detail' => ''],
        'escrow' => ['ok' => false, 'detail' => ''],
    ];

    try {
        zinesh_demo_ensure_users();
        $employer = zinesh_find_user_by_uid(ZINESH_DEMO_EMPLOYER_UID);
        $worker = zinesh_find_user_by_uid(ZINESH_DEMO_WORKER_UID);
        $employerAvail = $employer
            ? round((float)$employer['usdtBalance'] - (float)$employer['escrowBalance'], 2)
            : 0.0;
        $checks['wallet']['ok'] = $employer !== null
            && $worker !== null
            && $employerAvail + 1e-9 >= ZINESH_DEMO_BALANCE_TRY;
        $checks['wallet']['detail'] = sprintf(
            'employer=%s worker=%s avail=%s',
            $employer ? 'yes' : 'no',
            $worker ? 'yes' : 'no',
            number_format($employerAvail, 0, ',', '.')
        );
    } catch (Throwable $e) {
        $checks['wallet']['detail'] = $e->getMessage();
        zinesh_demo_record_error($e->getMessage());
    }

    try {
        $rooms = zinesh_json_read('escrow_rooms.json');
        $checks['room']['ok'] = is_array($rooms);
        $checks['room']['detail'] = 'rooms=' . (is_array($rooms) ? count($rooms) : 0);
    } catch (Throwable $e) {
        $checks['room']['detail'] = $e->getMessage();
        zinesh_demo_record_error($e->getMessage());
    }

    try {
        require_once __DIR__ . '/tl_mode_lib.php';
        $tl = zinesh_tl_mode_enabled();
        $checks['status']['ok'] = $tl;
        $checks['status']['detail'] = $tl ? 'tl_mode=1' : 'tl_mode=0';
    } catch (Throwable $e) {
        $checks['status']['detail'] = $e->getMessage();
        zinesh_demo_record_error($e->getMessage());
    }

    try {
        $probe = zinesh_json_read('users.json');
        $checks['json']['ok'] = is_array($probe);
        $checks['json']['detail'] = is_array($probe) ? 'users=' . count($probe) : 'read_fail';
    } catch (Throwable $e) {
        $checks['json']['detail'] = $e->getMessage();
        zinesh_demo_record_error($e->getMessage());
    }

    try {
        require_once __DIR__ . '/escrow_room_lib.php';
        $checks['escrow']['ok'] = function_exists('zinesh_escrow_room_connect');
        $checks['escrow']['detail'] = $checks['escrow']['ok'] ? 'escrow_room_lib=ok' : 'missing';
    } catch (Throwable $e) {
        $checks['escrow']['detail'] = $e->getMessage();
        zinesh_demo_record_error($e->getMessage());
    }

    $allOk = true;
    foreach ($checks as $check) {
        if (empty($check['ok'])) {
            $allOk = false;
            break;
        }
    }

    return [
        'ok' => $allOk,
        'demoEnabled' => zinesh_demo_mode_enabled(),
        'checks' => $checks,
        'lastError' => zinesh_demo_last_error(),
        'dataDir' => zinesh_resolve_data_dir(),
        'at' => date('c'),
    ];
}
