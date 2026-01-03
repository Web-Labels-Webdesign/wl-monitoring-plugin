<?php

declare(strict_types=1);

namespace WlMonitoring\Service;

use Doctrine\DBAL\Connection;

class PerformanceInfoCollector
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $environment,
        private readonly bool $debug,
        private readonly bool $adminWorkerEnabled
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $issues = [];

        $phpSettings = $this->getPhpSettings($issues);
        $mysqlSettings = $this->getMysqlSettings($issues);
        $appSettings = $this->getAppSettings($issues);

        return [
            'php' => $phpSettings,
            'mysql' => $mysqlSettings,
            'app' => $appSettings,
            'issues' => $issues,
        ];
    }

    /**
     * @param array<int, array<string, string>> $issues
     *
     * @return array<string, mixed>
     */
    private function getPhpSettings(array &$issues): array
    {
        $opcacheEnabled = \function_exists('opcache_get_status') && opcache_get_status() !== false;

        if (!$opcacheEnabled && $this->environment === 'prod') {
            $issues[] = ['type' => 'warning', 'message' => 'OPcache is disabled in production'];
        }

        $opcacheMemory = null;
        if ($opcacheEnabled) {
            $status = opcache_get_status();
            if (\is_array($status) && isset($status['memory_usage']['used_memory'])) {
                $opcacheMemory = (int) round(($status['memory_usage']['used_memory'] + $status['memory_usage']['free_memory']) / 1024 / 1024);
            }
        }

        $zendAssertions = (int) ini_get('zend.assertions');
        if ($zendAssertions !== -1 && $this->environment === 'prod') {
            $issues[] = ['type' => 'warning', 'message' => 'zend.assertions should be -1 in production'];
        }

        $realpathCacheSize = ini_get('realpath_cache_size');
        $realpathCacheSizeBytes = $this->parseBytes($realpathCacheSize ?: '0');
        if ($realpathCacheSizeBytes < 4 * 1024 * 1024) {
            $issues[] = ['type' => 'info', 'message' => 'realpath_cache_size should be at least 4M'];
        }

        return [
            'opcache_enabled' => $opcacheEnabled,
            'opcache_memory_mb' => $opcacheMemory,
            'realpath_cache_size' => $realpathCacheSize,
            'zend_assertions' => $zendAssertions,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
        ];
    }

    /**
     * @param array<int, array<string, string>> $issues
     *
     * @return array<string, mixed>
     */
    private function getMysqlSettings(array &$issues): array
    {
        try {
            $variables = $this->connection->fetchAllKeyValue(
                'SHOW VARIABLES WHERE Variable_name IN (
                    \'sql_mode\',
                    \'group_concat_max_len\',
                    \'innodb_buffer_pool_size\',
                    \'slow_query_log\',
                    \'long_query_time\'
                )'
            );

            $sqlMode = $variables['sql_mode'] ?? '';
            if (str_contains($sqlMode, 'ONLY_FULL_GROUP_BY')) {
                $issues[] = ['type' => 'info', 'message' => 'SQL mode contains ONLY_FULL_GROUP_BY which may cause issues'];
            }

            $groupConcatMaxLen = isset($variables['group_concat_max_len']) ? (int) $variables['group_concat_max_len'] : 0;
            if ($groupConcatMaxLen < 320000) {
                $issues[] = ['type' => 'warning', 'message' => 'group_concat_max_len should be at least 320000'];
            }

            $bufferPoolSize = isset($variables['innodb_buffer_pool_size']) ? (int) $variables['innodb_buffer_pool_size'] : 0;

            return [
                'sql_mode' => $sqlMode,
                'group_concat_max_len' => $groupConcatMaxLen,
                'innodb_buffer_pool_size_mb' => (int) round($bufferPoolSize / 1024 / 1024),
                'slow_query_log' => ($variables['slow_query_log'] ?? 'OFF') === 'ON',
                'long_query_time' => isset($variables['long_query_time']) ? (float) $variables['long_query_time'] : null,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<int, array<string, string>> $issues
     *
     * @return array<string, mixed>
     */
    private function getAppSettings(array &$issues): array
    {
        if ($this->debug && $this->environment === 'prod') {
            $issues[] = ['type' => 'critical', 'message' => 'Debug mode is enabled in production'];
        }

        if ($this->environment !== 'prod') {
            $issues[] = ['type' => 'warning', 'message' => 'APP_ENV is not set to prod'];
        }

        if ($this->adminWorkerEnabled) {
            $issues[] = ['type' => 'info', 'message' => 'Admin worker is enabled, consider using CLI workers for better performance'];
        }

        return [
            'env' => $this->environment,
            'debug' => $this->debug,
            'admin_worker_enabled' => $this->adminWorkerEnabled,
        ];
    }

    private function parseBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[\strlen($value) - 1]);
        $numericValue = (int) $value;

        switch ($last) {
            case 'g':
                $numericValue *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $numericValue *= 1024 * 1024;
                break;
            case 'k':
                $numericValue *= 1024;
                break;
        }

        return $numericValue;
    }
}
