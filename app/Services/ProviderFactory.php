<?php

namespace ClientVerification\Services;

use ClientVerification\Providers\KycProviderInterface;
use ClientVerification\Providers\ManualProvider;
use ClientVerification\Providers\DiditProvider;

/**
 * Builds KYC provider instances from configuration. The core engine uses this
 * factory so new providers can be added without modifying the engine.
 */
class ProviderFactory
{
    public static function make(string $method, array $config = []): KycProviderInterface
    {
        switch ($method) {
            case 'didit':
            case 'hybrid':
                $baseUrl = $config['didit_base_url'] ?? ($config['base_url'] ?? 'https://verification.didit.me');
                if (empty($baseUrl) || strpos($baseUrl, 'apikyc.didit.me') !== false) {
                    $baseUrl = 'https://verification.didit.me';
                }
                return new DiditProvider(
                    $config['didit_api_key'] ?? ($config['api_key'] ?? ''),
                    $config['didit_workflow_id'] ?? ($config['workflow_id'] ?? ''),
                    $config['didit_webhook_secret'] ?? ($config['webhook_secret'] ?? ''),
                    $config['callback_url'] ?? '',
                    $baseUrl
                );
            case 'manual':
            default:
                return new ManualProvider();
        }
    }
}
