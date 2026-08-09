<?php
declare(strict_types=1);

/**
 * Copilot provider seçimi — mock | openai (yapılandırılabilir).
 */
require_once __DIR__ . '/copilot_provider_interface.php';
require_once __DIR__ . '/copilot_mock_provider.php';
require_once __DIR__ . '/providers/openai_config.php';
require_once __DIR__ . '/providers/openai_provider.php';

function zinesh_copilot_resolve_provider(): ZineshCopilotProviderInterface
{
    $name = zinesh_copilot_provider_name();
    if ($name === 'openai' && zinesh_openai_provider_is_configured()) {
        return zinesh_openai_copilot_provider_create();
    }
    return new ZineshCopilotMockProvider();
}
