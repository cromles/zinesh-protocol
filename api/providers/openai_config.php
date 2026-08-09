<?php
declare(strict_types=1);

/**
 * OpenAI Copilot provider yapılandırması — API key ve model kod içine gömülmez.
 */
require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/security_lib.php';

/** @return array<string,mixed> */
function zinesh_copilot_config_section(): array
{
    $cfg = zinesh_config();
    $section = $cfg['copilot'] ?? [];
    return is_array($section) ? $section : [];
}

/** @return array<string,mixed> */
function zinesh_openai_copilot_config(): array
{
    $section = zinesh_copilot_config_section();
    $openai = $section['openai'] ?? [];
    return is_array($openai) ? $openai : [];
}

function zinesh_copilot_provider_name(): string
{
    $env = getenv('ZINESH_COPILOT_PROVIDER');
    if (is_string($env) && trim($env) !== '') {
        return strtolower(trim($env));
    }
    return 'mock';
}

function zinesh_openai_api_key(): string
{
    foreach (['OPENAI_API_KEY', 'ZINESH_OPENAI_API_KEY'] as $envKey) {
        $val = getenv($envKey);
        if (is_string($val) && trim($val) !== '') {
            return trim($val);
        }
    }

    $secrets = zinesh_load_server_secrets();
    $fromSecrets = trim((string)($secrets['openai_api_key'] ?? ''));
    if ($fromSecrets !== '') {
        return $fromSecrets;
    }

    $cfg = zinesh_openai_copilot_config();
    return trim((string)($cfg['api_key'] ?? ''));
}

function zinesh_openai_model(): string
{
    $env = getenv('ZINESH_OPENAI_MODEL');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }

    $secrets = zinesh_load_server_secrets();
    $fromSecrets = trim((string)($secrets['openai_model'] ?? ''));
    if ($fromSecrets !== '') {
        return $fromSecrets;
    }

    $cfg = zinesh_openai_copilot_config();
    return trim((string)($cfg['model'] ?? ''));
}

function zinesh_openai_api_base(): string
{
    $env = getenv('ZINESH_OPENAI_API_BASE');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }

    $secrets = zinesh_load_server_secrets();
    $fromSecrets = trim((string)($secrets['openai_api_base'] ?? ''));
    if ($fromSecrets !== '') {
        return rtrim($fromSecrets, '/');
    }

    $cfg = zinesh_openai_copilot_config();
    $base = trim((string)($cfg['api_base'] ?? ''));
    if ($base !== '') {
        return rtrim($base, '/');
    }

    return 'https://api.openai.com/v1';
}

function zinesh_openai_timeout_seconds(): int
{
    $env = getenv('ZINESH_OPENAI_TIMEOUT_SECONDS');
    if (is_string($env) && trim($env) !== '' && ctype_digit(trim($env))) {
        $timeout = (int)trim($env);
        return $timeout > 0 ? $timeout : 30;
    }

    $secrets = zinesh_load_server_secrets();
    $fromSecrets = (int)($secrets['openai_timeout_seconds'] ?? 0);
    if ($fromSecrets > 0) {
        return $fromSecrets;
    }

    $cfg = zinesh_openai_copilot_config();
    $timeout = (int)($cfg['timeout_seconds'] ?? 0);
    return $timeout > 0 ? $timeout : 30;
}

function zinesh_openai_provider_is_configured(): bool
{
    return zinesh_openai_api_key() !== '' && zinesh_openai_model() !== '';
}
