<?php

declare(strict_types=1);

namespace WlMonitoring\Service;

use Shopware\Core\Framework\Adapter\Cache\CacheDecorator;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\Finder\Finder;

class CacheInfoCollector
{
    public function __construct(
        private readonly string $cacheDir,
        private readonly string $projectDir,
        private readonly AdapterInterface $appCache,
        private readonly AdapterInterface $httpCache
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $pools = [];

        // App cache pool (cache.object)
        $pools[] = $this->collectPoolInfo('app', $this->appCache);

        // HTTP cache pool
        $pools[] = $this->collectPoolInfo('http', $this->httpCache);

        // File-based pools (theme, var/cache size)
        $pools = array_merge($pools, $this->getFilePools());

        $totalSize = array_sum(array_column($pools, 'size_bytes'));

        return [
            'pools' => $pools,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            'var_cache_size_mb' => $this->getDirectorySizeMb($this->cacheDir),
            'redis' => $this->getRedisInfo($this->appCache),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectPoolInfo(string $name, AdapterInterface $adapter): array
    {
        $unwrapped = $this->unwrapAdapter($adapter);
        $type = $this->getAdapterType($unwrapped);
        $sizeBytes = $this->getAdapterSize($unwrapped);

        return [
            'name' => $name,
            'type' => $type,
            'size_bytes' => $sizeBytes,
            'size_mb' => round($sizeBytes / 1024 / 1024, 2),
        ];
    }

    private function unwrapAdapter(AdapterInterface $adapter): AdapterInterface
    {
        // Unwrap Shopware's CacheDecorator
        if (class_exists(CacheDecorator::class) && $adapter instanceof CacheDecorator) {
            $func = \Closure::bind(fn () => $this->decorated, $adapter, CacheDecorator::class);
            $decorated = $func();
            if ($decorated instanceof AdapterInterface) {
                return $this->unwrapAdapter($decorated);
            }
        }

        // Unwrap TagAwareAdapter
        if ($adapter instanceof TagAwareAdapter) {
            $func = \Closure::bind(fn () => $this->pool, $adapter, TagAwareAdapter::class);
            $pool = $func();
            if ($pool instanceof AdapterInterface) {
                return $this->unwrapAdapter($pool);
            }
        }

        // Unwrap TraceableAdapter
        if ($adapter instanceof TraceableAdapter) {
            $func = \Closure::bind(fn () => $this->pool, $adapter, TraceableAdapter::class);
            $pool = $func();
            if ($pool instanceof AdapterInterface) {
                return $this->unwrapAdapter($pool);
            }
        }

        return $adapter;
    }

    private function getAdapterType(AdapterInterface $adapter): string
    {
        return match (true) {
            $adapter instanceof RedisTagAwareAdapter => 'Redis (TagAware)',
            $adapter instanceof RedisAdapter => 'Redis',
            $adapter instanceof FilesystemAdapter => 'Filesystem',
            default => basename(str_replace('\\', '/', $adapter::class)),
        };
    }

    private function getAdapterSize(AdapterInterface $adapter): int
    {
        return match (true) {
            $adapter instanceof RedisAdapter,
            $adapter instanceof RedisTagAwareAdapter => $this->getRedisMemory($adapter),
            $adapter instanceof FilesystemAdapter => $this->getFilesystemSize($adapter),
            default => 0,
        };
    }

    private function getRedisMemory(AdapterInterface $adapter): int
    {
        try {
            $redis = $this->getRedisConnection($adapter);
            $info = $redis->info();

            return (int) ($info['used_memory'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return \Redis|\RedisCluster
     */
    private function getRedisConnection(AdapterInterface $adapter): \Redis|\RedisCluster
    {
        $class = $adapter instanceof RedisTagAwareAdapter
            ? RedisTagAwareAdapter::class
            : RedisAdapter::class;

        $func = \Closure::bind(fn () => $this->redis, $adapter, $class);

        return $func();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRedisInfo(AdapterInterface $adapter): ?array
    {
        $unwrapped = $this->unwrapAdapter($adapter);

        if (!$unwrapped instanceof RedisAdapter && !$unwrapped instanceof RedisTagAwareAdapter) {
            return null;
        }

        try {
            $redis = $this->getRedisConnection($unwrapped);
            $info = $redis->info();

            return [
                // Basic info
                'version' => $info['redis_version'] ?? null,
                'used_memory' => (int) ($info['used_memory'] ?? 0),
                'used_memory_human' => $info['used_memory_human'] ?? null,
                'maxmemory' => (int) ($info['maxmemory'] ?? 0),
                'maxmemory_human' => $info['maxmemory_human'] ?? null,
                'connected_clients' => (int) ($info['connected_clients'] ?? 0),
                'uptime_days' => (int) ($info['uptime_in_days'] ?? 0),
                // Performance metrics
                'ops_per_sec' => (int) ($info['instantaneous_ops_per_sec'] ?? 0),
                'hit_rate' => $this->calculateRedisHitRate($info),
                'memory_fragmentation_ratio' => (float) ($info['mem_fragmentation_ratio'] ?? 1.0),
                // Client metrics
                'blocked_clients' => (int) ($info['blocked_clients'] ?? 0),
                // Key metrics
                'evicted_keys' => (int) ($info['evicted_keys'] ?? 0),
                'expired_keys' => (int) ($info['expired_keys'] ?? 0),
                'keyspace_hits' => (int) ($info['keyspace_hits'] ?? 0),
                'keyspace_misses' => (int) ($info['keyspace_misses'] ?? 0),
                // Connection metrics
                'total_connections_received' => (int) ($info['total_connections_received'] ?? 0),
                'rejected_connections' => (int) ($info['rejected_connections'] ?? 0),
                // Persistence metrics
                'rdb_last_save_time' => (int) ($info['rdb_last_save_time'] ?? 0),
                'rdb_changes_since_last_save' => (int) ($info['rdb_changes_since_last_save'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Calculate Redis cache hit rate percentage.
     */
    private function calculateRedisHitRate(array $info): ?float
    {
        $hits = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $total = $hits + $misses;

        return $total > 0 ? round(($hits / $total) * 100, 2) : null;
    }

    private function getFilesystemSize(FilesystemAdapter $adapter): int
    {
        try {
            $func = \Closure::bind(fn () => $this->directory, $adapter, FilesystemAdapter::class);
            $path = $func();

            return $this->getDirectorySizeBytes($path);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getFilePools(): array
    {
        $pools = [];

        // Theme cache
        $themePath = $this->projectDir . '/public/theme';
        if (is_dir($themePath)) {
            $sizeBytes = $this->getDirectorySizeBytes($themePath);
            $pools[] = [
                'name' => 'theme',
                'type' => 'Filesystem',
                'size_bytes' => $sizeBytes,
                'size_mb' => round($sizeBytes / 1024 / 1024, 2),
            ];
        }

        return $pools;
    }

    private function getDirectorySizeBytes(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;

        try {
            $finder = new Finder();
            $finder->in($path)->files()->ignoreDotFiles(false);

            foreach ($finder as $file) {
                $size += $file->getSize();
            }
        } catch (\Throwable) {
            return 0;
        }

        return $size;
    }

    private function getDirectorySizeMb(string $path): float
    {
        return round($this->getDirectorySizeBytes($path) / 1024 / 1024, 2);
    }
}
