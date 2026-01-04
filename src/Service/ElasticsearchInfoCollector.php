<?php

declare(strict_types=1);

namespace WlMonitoring\Service;

class ElasticsearchInfoCollector
{
    public function __construct(
        private readonly bool $elasticsearchEnabled,
        private readonly ?object $elasticsearchClient = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        if (!$this->elasticsearchEnabled) {
            return [
                'enabled' => false,
                'status' => 'disabled',
            ];
        }

        if ($this->elasticsearchClient === null) {
            return [
                'enabled' => true,
                'status' => 'client_not_available',
            ];
        }

        try {
            return $this->getClusterInfo();
        } catch (\Throwable $e) {
            return [
                'enabled' => true,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getClusterInfo(): array
    {
        // Use reflection to call methods on the ES client to avoid type issues
        $client = $this->elasticsearchClient;

        // Get cluster health
        $healthMethod = [$client, 'cluster'];
        if (!\is_callable($healthMethod)) {
            return [
                'enabled' => true,
                'status' => 'incompatible_client',
            ];
        }

        $cluster = $client->cluster();

        // Get health
        $healthResponse = $cluster->health();
        $health = $this->extractResponseData($healthResponse);

        // Get cluster stats for aggregated metrics
        $clusterStats = $this->getClusterStats($client);

        // Get node stats for JVM metrics
        $jvmHeapPercent = $this->getJvmHeapUsedPercent($client);

        // Get indices
        $catMethod = [$client, 'cat'];
        $indices = [];
        $totalDocs = 0;
        $totalSizeBytes = 0;

        if (\is_callable($catMethod)) {
            $cat = $client->cat();
            $indicesResponse = $cat->indices(['format' => 'json']);
            $indicesData = $this->extractResponseData($indicesResponse);

            if (\is_array($indicesData)) {
                foreach ($indicesData as $index) {
                    // Only include Shopware indices
                    $indexName = $index['index'] ?? '';
                    if (!str_starts_with($indexName, 'sw_') && !str_starts_with($indexName, 'shopware_')) {
                        continue;
                    }

                    $docCount = isset($index['docs.count']) ? (int) $index['docs.count'] : 0;
                    $sizeBytes = $this->parseSizeToBytes($index['store.size'] ?? '0');
                    $totalDocs += $docCount;
                    $totalSizeBytes += $sizeBytes;

                    $indices[] = [
                        'name' => $indexName,
                        'health' => $index['health'] ?? 'unknown',
                        'docs' => $docCount,
                        'size_mb' => round($sizeBytes / 1024 / 1024, 2),
                    ];
                }
            }
        }

        return [
            'enabled' => true,
            'cluster_name' => $health['cluster_name'] ?? 'unknown',
            'cluster_health' => $health['status'] ?? 'unknown',
            'nodes' => $health['number_of_nodes'] ?? 0,
            'indices' => $indices,
            'status' => 'ok',
            // Deep metrics
            'active_shards' => $health['active_shards'] ?? 0,
            'unassigned_shards' => $health['unassigned_shards'] ?? 0,
            'pending_tasks' => $health['number_of_pending_tasks'] ?? 0,
            'jvm_heap_used_percent' => $jvmHeapPercent,
            'total_docs' => $totalDocs,
            'total_size_bytes' => $totalSizeBytes,
            'query_total' => $clusterStats['query_total'] ?? 0,
            'query_time_ms' => $clusterStats['query_time_ms'] ?? 0,
            'indexing_total' => $clusterStats['indexing_total'] ?? 0,
            'indexing_time_ms' => $clusterStats['indexing_time_ms'] ?? 0,
        ];
    }

    /**
     * Get cluster-wide stats for search and indexing metrics.
     *
     * @return array<string, int>
     */
    private function getClusterStats(object $client): array
    {
        try {
            $statsMethod = [$client, 'cluster'];
            if (!\is_callable($statsMethod)) {
                return [];
            }

            $cluster = $client->cluster();
            $statsResponse = $cluster->stats();
            $stats = $this->extractResponseData($statsResponse);

            return [
                'query_total' => (int) ($stats['indices']['search']['query_total'] ?? 0),
                'query_time_ms' => (int) ($stats['indices']['search']['query_time_in_millis'] ?? 0),
                'indexing_total' => (int) ($stats['indices']['indexing']['index_total'] ?? 0),
                'indexing_time_ms' => (int) ($stats['indices']['indexing']['index_time_in_millis'] ?? 0),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get average JVM heap usage across all nodes.
     */
    private function getJvmHeapUsedPercent(object $client): ?float
    {
        try {
            $nodesMethod = [$client, 'nodes'];
            if (!\is_callable($nodesMethod)) {
                return null;
            }

            $nodes = $client->nodes();
            $statsResponse = $nodes->stats(['metric' => 'jvm']);
            $stats = $this->extractResponseData($statsResponse);

            $nodeStats = $stats['nodes'] ?? [];
            if (empty($nodeStats)) {
                return null;
            }

            $totalPercent = 0;
            $nodeCount = 0;

            foreach ($nodeStats as $node) {
                if (isset($node['jvm']['mem']['heap_used_percent'])) {
                    $totalPercent += (float) $node['jvm']['mem']['heap_used_percent'];
                    $nodeCount++;
                }
            }

            return $nodeCount > 0 ? round($totalPercent / $nodeCount, 1) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse size string to bytes.
     */
    private function parseSizeToBytes(string $size): int
    {
        $size = strtolower(trim($size));

        if (str_ends_with($size, 'gb')) {
            return (int) ((float) $size * 1024 * 1024 * 1024);
        }

        if (str_ends_with($size, 'mb')) {
            return (int) ((float) $size * 1024 * 1024);
        }

        if (str_ends_with($size, 'kb')) {
            return (int) ((float) $size * 1024);
        }

        if (str_ends_with($size, 'b')) {
            return (int) $size;
        }

        return 0;
    }

    private function extractResponseData(mixed $response): mixed
    {
        // Handle different Elasticsearch client versions
        if (\is_array($response)) {
            return $response;
        }

        if (\is_object($response)) {
            // Elasticsearch 8.x returns Elastic\Elasticsearch\Response\Elasticsearch
            if (method_exists($response, 'asArray')) {
                return $response->asArray();
            }

            // Fallback for older clients
            if (method_exists($response, 'getBody')) {
                $body = $response->getBody();
                if (\is_string($body)) {
                    return json_decode($body, true);
                }
            }
        }

        return $response;
    }

    private function parseSize(string $size): float
    {
        $size = strtolower(trim($size));

        if (str_ends_with($size, 'gb')) {
            return round((float) $size * 1024, 2);
        }

        if (str_ends_with($size, 'mb')) {
            return round((float) $size, 2);
        }

        if (str_ends_with($size, 'kb')) {
            return round((float) $size / 1024, 2);
        }

        if (str_ends_with($size, 'b')) {
            return round((float) $size / 1024 / 1024, 2);
        }

        return 0.0;
    }
}
