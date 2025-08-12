<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Apcu\Component;

use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Contract\Logging\Logger;

/**
 * Minimal connection manager for APCu to maintain consistency with other drivers.
 * APCu doesn't require connection management, but this provides environment validation.
 */
final class ApcuConnectionManager
{
    private bool $isValidated = false;

    public function __construct(
        private Logger $logger
    ) {
    }

    public function ensureConnection(): void
    {
        if ($this->isValidated) {
            return;
        }

        $this->validateEnvironment();
        $this->isValidated = true;
    }

    public function reconnect(): void
    {
        // APCu doesn't need reconnection
        $this->ensureConnection();
    }

    public function isConnected(): bool
    {
        return $this->isValidated && apcu_enabled();
    }

    private function validateEnvironment(): void
    {
        if (!extension_loaded('apcu')) {
            throw ConfigurationException::missingExtension('apcu');
        }

        if (!apcu_enabled()) {
            throw ConfigurationException::invalidConfiguration('apcu', 'APCu is not enabled. Check apc.enabled in php.ini');
        }

        if (PHP_SAPI === 'cli' && !ini_get('apc.enable_cli')) {
            throw ConfigurationException::invalidConfiguration('apcu', 'APCu is not enabled for CLI. Set apc.enable_cli=1 in php.ini');
        }

        $this->logger->debug('APCu environment validated successfully');
    }
}
