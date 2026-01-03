<?php

declare(strict_types=1);

namespace WlMonitoring\Service;

use Doctrine\DBAL\Connection;

class SystemInfoCollector
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $projectDir,
        private readonly string $environment
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        return [
            'php' => $this->getPhpInfo(),
            'mysql' => $this->getMySqlInfo(),
            'server' => $this->getServerInfo(),
            'shopware' => $this->getShopwareInfo(),
        ];
    }

    /**
     * Lightweight health check data
     *
     * @return array<string, mixed>
     */
    public function getHealthData(): array
    {
        $mysqlOk = false;
        try {
            $this->connection->fetchOne('SELECT 1');
            $mysqlOk = true;
        } catch (\Throwable) {
            // Connection failed
        }

        return [
            'mysql_connection' => $mysqlOk,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'php_version' => \PHP_VERSION,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getPhpInfo(): array
    {
        return [
            'version' => \PHP_VERSION,
            'extensions' => get_loaded_extensions(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'opcache_enabled' => \function_exists('opcache_get_status') && opcache_get_status() !== false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getMySqlInfo(): array
    {
        try {
            $version = $this->connection->fetchOne('SELECT VERSION()');
            $variables = $this->connection->fetchAllKeyValue(
                'SHOW VARIABLES WHERE Variable_name IN (\'max_connections\', \'innodb_buffer_pool_size\', \'wait_timeout\')'
            );

            return [
                'version' => $version,
                'connection_ok' => true,
                'max_connections' => isset($variables['max_connections']) ? (int) $variables['max_connections'] : null,
                'innodb_buffer_pool_size' => isset($variables['innodb_buffer_pool_size']) ? (int) $variables['innodb_buffer_pool_size'] : null,
            ];
        } catch (\Throwable $e) {
            return [
                'version' => null,
                'connection_ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getServerInfo(): array
    {
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);

        $diskFree = disk_free_space($this->projectDir);
        $diskTotal = disk_total_space($this->projectDir);

        return [
            'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'memory_peak_mb' => round($memoryPeak / 1024 / 1024, 2),
            'disk_free_gb' => $diskFree !== false ? round($diskFree / 1024 / 1024 / 1024, 2) : null,
            'disk_total_gb' => $diskTotal !== false ? round($diskTotal / 1024 / 1024 / 1024, 2) : null,
            'disk_usage_percent' => ($diskFree !== false && $diskTotal !== false && $diskTotal > 0)
                ? round((1 - $diskFree / $diskTotal) * 100, 2)
                : null,
            'load_average' => \function_exists('sys_getloadavg') ? sys_getloadavg() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getShopwareInfo(): array
    {
        $version = null;

        // Try to get Shopware version from installed packages
        $composerLockPath = $this->projectDir . '/composer.lock';
        if (file_exists($composerLockPath)) {
            $composerLock = json_decode((string) file_get_contents($composerLockPath), true);
            if (\is_array($composerLock) && isset($composerLock['packages'])) {
                foreach ($composerLock['packages'] as $package) {
                    if (isset($package['name']) && $package['name'] === 'shopware/core') {
                        $version = $package['version'] ?? null;
                        break;
                    }
                }
            }
        }

        // Fallback: try to get from Kernel constant if available
        if ($version === null && \defined('Shopware\Core\Kernel::SHOPWARE_FALLBACK_VERSION')) {
            $version = \constant('Shopware\Core\Kernel::SHOPWARE_FALLBACK_VERSION');
        }

        return [
            'version' => $version,
            'env' => $this->environment,
        ];
    }
}
