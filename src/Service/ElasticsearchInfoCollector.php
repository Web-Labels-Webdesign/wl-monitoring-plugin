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

        // Get indices
        $catMethod = [$client, 'cat'];
        $indices = [];

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

                    $indices[] = [
                        'name' => $indexName,
                        'health' => $index['health'] ?? 'unknown',
                        'docs' => isset($index['docs.count']) ? (int) $index['docs.count'] : 0,
                        'size_mb' => $this->parseSize($index['store.size'] ?? '0'),
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
        ];
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
