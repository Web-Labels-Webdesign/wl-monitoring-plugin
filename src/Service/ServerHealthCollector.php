<?php

declare(strict_types=1);

namespace WlMonitoring\Service;

use Doctrine\DBAL\Connection;

class ServerHealthCollector
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        return [
            'cpu' => $this->getCpuInfo(),
            'memory' => $this->getMemoryInfo(),
            'php_fpm' => $this->getPhpFpmStats(),
            'mysql' => $this->getMysqlDeepStats(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getCpuInfo(): array
    {
        $loadAvg = \function_exists('sys_getloadavg') ? sys_getloadavg() : null;
        $cpuCores = $this->getCpuCores();

        return [
            'load_average_1m' => $loadAvg[0] ?? null,
            'load_average_5m' => $loadAvg[1] ?? null,
            'load_average_15m' => $loadAvg[2] ?? null,
            'cpu_cores' => $cpuCores,
            // Normalized load (load / cores) - useful for comparison across servers
            'normalized_load_1m' => ($loadAvg[0] ?? null) !== null && $cpuCores !== null
                ? round($loadAvg[0] / $cpuCores, 2)
                : null,
        ];
    }

    private function getCpuCores(): ?int
    {
        // Try nproc first (Linux)
        $nproc = @shell_exec('nproc 2>/dev/null');
        if ($nproc !== null && $nproc !== false) {
            $cores = (int) trim($nproc);
            if ($cores > 0) {
                return $cores;
            }
        }

        // Fallback: read from /proc/cpuinfo (Linux)
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                $count = preg_match_all('/^processor/m', $cpuinfo);
                if ($count !== false && $count > 0) {
                    return $count;
                }
            }
        }

        // Fallback: sysctl (macOS/BSD)
        $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
        if ($sysctl !== null && $sysctl !== false) {
            $cores = (int) trim($sysctl);
            if ($cores > 0) {
                return $cores;
            }
        }

        return null;
    }

    /**
     * Get system memory (RAM) information.
     *
     * @return array<string, mixed>|null
     */
    private function getMemoryInfo(): ?array
    {
        // Try Linux /proc/meminfo first
        if (is_readable('/proc/meminfo')) {
            return $this->parseLinuxMemInfo();
        }

        // Fallback for macOS/BSD
        return $this->getMacOsMemInfo();
    }

    /**
     * Parse memory info from Linux /proc/meminfo.
     *
     * @return array<string, int|float>|null
     */
    private function parseLinuxMemInfo(): ?array
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo === false) {
            return null;
        }

        $values = [];
        $lines = explode("\n", $meminfo);
        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $line, $matches)) {
                $values[$matches[1]] = (int) $matches[2];
            }
        }

        $totalKb = $values['MemTotal'] ?? 0;
        $freeKb = $values['MemFree'] ?? 0;
        $availableKb = $values['MemAvailable'] ?? null;
        $buffersKb = $values['Buffers'] ?? 0;
        $cachedKb = $values['Cached'] ?? 0;
        $swapTotalKb = $values['SwapTotal'] ?? 0;
        $swapFreeKb = $values['SwapFree'] ?? 0;

        // MemAvailable is preferred (kernel 3.14+), fallback to free+buffers+cached
        if ($availableKb === null) {
            $availableKb = $freeKb + $buffersKb + $cachedKb;
        }

        $usedKb = $totalKb - $availableKb;
        $swapUsedKb = $swapTotalKb - $swapFreeKb;

        $totalMb = (int) round($totalKb / 1024);
        $availableMb = (int) round($availableKb / 1024);
        $usedMb = (int) round($usedKb / 1024);
        $usagePercent = $totalKb > 0 ? round(($usedKb / $totalKb) * 100, 2) : 0;

        return [
            'total_mb' => $totalMb,
            'available_mb' => $availableMb,
            'used_mb' => $usedMb,
            'usage_percent' => $usagePercent,
            'swap_total_mb' => (int) round($swapTotalKb / 1024),
            'swap_used_mb' => (int) round($swapUsedKb / 1024),
            'swap_free_mb' => (int) round($swapFreeKb / 1024),
        ];
    }

    /**
     * Get memory info on macOS/BSD using sysctl and vm_stat.
     *
     * @return array<string, int|float>|null
     */
    private function getMacOsMemInfo(): ?array
    {
        // Get total memory via sysctl
        $totalBytes = @shell_exec('sysctl -n hw.memsize 2>/dev/null');
        if ($totalBytes === null || $totalBytes === false) {
            return null;
        }

        $totalMb = (int) round((int) trim($totalBytes) / 1024 / 1024);

        // Try to get page size and free pages from vm_stat
        $vmStat = @shell_exec('vm_stat 2>/dev/null');
        if ($vmStat === null || $vmStat === false) {
            return [
                'total_mb' => $totalMb,
                'available_mb' => null,
                'used_mb' => null,
                'usage_percent' => null,
                'swap_total_mb' => null,
                'swap_used_mb' => null,
                'swap_free_mb' => null,
            ];
        }

        // Parse vm_stat output
        $pageSize = 4096; // Default page size
        if (preg_match('/page size of (\d+) bytes/', $vmStat, $m)) {
            $pageSize = (int) $m[1];
        }

        $freePages = 0;
        $inactivePages = 0;
        if (preg_match('/Pages free:\s+(\d+)/', $vmStat, $m)) {
            $freePages = (int) $m[1];
        }
        if (preg_match('/Pages inactive:\s+(\d+)/', $vmStat, $m)) {
            $inactivePages = (int) $m[1];
        }

        $availableMb = (int) round(($freePages + $inactivePages) * $pageSize / 1024 / 1024);
        $usedMb = $totalMb - $availableMb;
        $usagePercent = $totalMb > 0 ? round(($usedMb / $totalMb) * 100, 2) : 0;

        return [
            'total_mb' => $totalMb,
            'available_mb' => $availableMb,
            'used_mb' => $usedMb,
            'usage_percent' => $usagePercent,
            'swap_total_mb' => null,
            'swap_used_mb' => null,
            'swap_free_mb' => null,
        ];
    }

    /**
     * Try to get PHP-FPM status via local status endpoint.
     *
     * @return array<string, mixed>|null
     */
    private function getPhpFpmStats(): ?array
    {
        // Common FPM status paths to try
        $statusPaths = [
            'http://127.0.0.1/fpm-status',
            'http://localhost/fpm-status',
            'http://127.0.0.1:9000/fpm-status', // Might be listening on socket
        ];

        foreach ($statusPaths as $url) {
            $stats = $this->fetchFpmStatus($url);
            if ($stats !== null) {
                return $stats;
            }
        }

        // FPM status not available
        return [
            'available' => false,
            'error' => 'FPM status page not accessible. Enable pm.status_path in php-fpm.conf.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchFpmStatus(string $url): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 1,
                'header' => 'Accept: application/json',
            ],
        ]);

        // Try JSON format first
        $jsonUrl = $url . '?json';
        $response = @file_get_contents($jsonUrl, false, $context);

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (!\is_array($data)) {
            return null;
        }

        return [
            'available' => true,
            'pool' => $data['pool'] ?? 'www',
            'process_manager' => $data['process manager'] ?? null,
            'active_processes' => $data['active processes'] ?? null,
            'idle_processes' => $data['idle processes'] ?? null,
            'total_processes' => $data['total processes'] ?? null,
            'listen_queue' => $data['listen queue'] ?? null,
            'listen_queue_len' => $data['listen queue len'] ?? null,
            'max_children_reached' => $data['max children reached'] ?? null,
            'slow_requests' => $data['slow requests'] ?? null,
            'accepted_conn' => $data['accepted conn'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getMysqlDeepStats(): array
    {
        try {
            $stats = [];

            // Get thread/connection status
            $statusVars = [
                'Threads_connected',
                'Threads_running',
                'Max_used_connections',
                'Slow_queries',
                'Innodb_buffer_pool_bytes_data',
                'Innodb_buffer_pool_read_requests',
                'Innodb_buffer_pool_reads',
                'Innodb_buffer_pool_pages_total',
                'Innodb_buffer_pool_pages_free',
                'Questions',
                'Uptime',
            ];

            $placeholders = implode(',', array_fill(0, \count($statusVars), '?'));
            $results = $this->connection->fetchAllKeyValue(
                "SHOW STATUS WHERE Variable_name IN ($placeholders)",
                $statusVars
            );

            // Thread statistics
            $stats['threads_connected'] = isset($results['Threads_connected'])
                ? (int) $results['Threads_connected']
                : null;
            $stats['threads_running'] = isset($results['Threads_running'])
                ? (int) $results['Threads_running']
                : null;
            $stats['max_used_connections'] = isset($results['Max_used_connections'])
                ? (int) $results['Max_used_connections']
                : null;

            // Slow queries
            $stats['slow_queries'] = isset($results['Slow_queries'])
                ? (int) $results['Slow_queries']
                : null;

            // InnoDB buffer pool
            $stats['innodb_buffer_pool_bytes_data'] = isset($results['Innodb_buffer_pool_bytes_data'])
                ? (int) $results['Innodb_buffer_pool_bytes_data']
                : null;

            // Calculate buffer pool hit rate
            $readRequests = (int) ($results['Innodb_buffer_pool_read_requests'] ?? 0);
            $diskReads = (int) ($results['Innodb_buffer_pool_reads'] ?? 0);
            $stats['innodb_buffer_pool_hit_rate'] = $readRequests > 0
                ? round((($readRequests - $diskReads) / $readRequests) * 100, 2)
                : 100.0;

            // Buffer pool utilization
            $totalPages = (int) ($results['Innodb_buffer_pool_pages_total'] ?? 0);
            $freePages = (int) ($results['Innodb_buffer_pool_pages_free'] ?? 0);
            $stats['innodb_buffer_pool_utilization'] = $totalPages > 0
                ? round((($totalPages - $freePages) / $totalPages) * 100, 2)
                : null;

            // Queries per second (approximate)
            $questions = (int) ($results['Questions'] ?? 0);
            $uptime = (int) ($results['Uptime'] ?? 1);
            $stats['queries_per_second'] = $uptime > 0
                ? round($questions / $uptime, 2)
                : null;

            // Get some configuration variables
            $configVars = $this->connection->fetchAllKeyValue(
                "SHOW VARIABLES WHERE Variable_name IN ('max_connections', 'slow_query_log', 'long_query_time')"
            );

            $stats['max_connections'] = isset($configVars['max_connections'])
                ? (int) $configVars['max_connections']
                : null;
            $stats['slow_query_log'] = ($configVars['slow_query_log'] ?? 'OFF') === 'ON';
            $stats['long_query_time'] = isset($configVars['long_query_time'])
                ? (float) $configVars['long_query_time']
                : null;

            // Connection utilization percentage
            if ($stats['threads_connected'] !== null && $stats['max_connections'] !== null) {
                $stats['connection_utilization'] = round(
                    ($stats['threads_connected'] / $stats['max_connections']) * 100,
                    2
                );
            }

            return $stats;
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}
