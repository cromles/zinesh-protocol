<?php
declare(strict_types=1);

require_once __DIR__ . '/protocol_constants.php';

function zinesh_tl_mode_enabled(): bool
{
    return (bool)(zinesh_protocol_constants()['tl_mode'] ?? false);
}

function zinesh_web3_enabled(): bool
{
    return (bool)(zinesh_protocol_constants()['web3_enabled'] ?? false);
}

function zinesh_escrow_uses_tl(): bool
{
    return zinesh_tl_mode_enabled();
}

function zinesh_deposits_enabled(): bool
{
    $cfg = zinesh_config();
    if (array_key_exists('deposits_enabled', $cfg)) {
        return (bool) $cfg['deposits_enabled'];
    }
    return false;
}
