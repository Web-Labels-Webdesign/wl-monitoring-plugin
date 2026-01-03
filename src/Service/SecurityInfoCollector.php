<?php

declare(strict_types=1);

namespace WlMonitoring\Service;

use Doctrine\DBAL\Connection;

class SecurityInfoCollector
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $shopwareVersion,
        private readonly string $projectDir
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        return [
            'shopware_version' => $this->getShopwareVersion(),
            'security_plugin' => $this->getSecurityPluginInfo(),
        ];
    }

    private function getShopwareVersion(): string
    {
        if (!empty($this->shopwareVersion) && $this->shopwareVersion !== '@SHOPWARE_VERSION@') {
            return $this->shopwareVersion;
        }

        // Fallback: read from composer.lock
        $composerLockPath = $this->projectDir . '/composer.lock';
        if (file_exists($composerLockPath)) {
            $composerLock = json_decode((string) file_get_contents($composerLockPath), true);
            if (\is_array($composerLock) && isset($composerLock['packages'])) {
                foreach ($composerLock['packages'] as $package) {
                    if (isset($package['name']) && $package['name'] === 'shopware/core') {
                        return ltrim($package['version'] ?? '', 'v');
                    }
                }
            }
        }

        return 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    private function getSecurityPluginInfo(): array
    {
        try {
            $result = $this->connection->fetchAssociative(
                'SELECT version, active FROM plugin WHERE name = :name AND installed_at IS NOT NULL',
                ['name' => 'SwagPlatformSecurity']
            );

            if ($result === false) {
                return [
                    'installed' => false,
                    'version' => null,
                    'active' => false,
                ];
            }

            return [
                'installed' => true,
                'version' => $result['version'],
                'active' => (bool) $result['active'],
            ];
        } catch (\Throwable) {
            return [
                'installed' => false,
                'version' => null,
                'active' => false,
            ];
        }
    }
}
