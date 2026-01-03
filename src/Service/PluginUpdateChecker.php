<?php

declare(strict_types=1);

namespace WlMonitoring\Service;

use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Store\Services\StoreClient;
use Shopware\Core\Framework\Store\Struct\ExtensionCollection;
use Shopware\Core\Framework\Store\Struct\ExtensionStruct;
use Symfony\Contracts\Cache\CacheInterface;

class PluginUpdateChecker
{
    private const EXTENSION_LIST_CACHE_KEY = 'extensionListStatus';

    private ?string $lastError = null;

    private ?int $lastUpdateCount = null;

    public function __construct(
        private readonly StoreClient $storeClient,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * Clear the extension update cache to force a fresh check.
     */
    public function clearCache(): bool
    {
        return $this->cache->delete(self::EXTENSION_LIST_CACHE_KEY);
    }

    /**
     * Check for available updates using Shopware's StoreClient.
     *
     * @param iterable<PluginEntity> $plugins
     *
     * @return array<string, string> Map of plugin name => available version
     */
    public function checkForUpdates(iterable $plugins, Context $context): array
    {
        $this->lastError = null;
        $this->lastUpdateCount = null;

        // Build ExtensionCollection from plugins (same pattern as ExtensionListingLoader)
        $extensionCollection = new ExtensionCollection();

        foreach ($plugins as $plugin) {
            $extension = new ExtensionStruct();
            $extension->setName($plugin->getName());
            $extension->setLabel($plugin->getLabel());
            $extension->setVersion($plugin->getVersion());
            $extension->setType(ExtensionStruct::EXTENSION_TYPE_PLUGIN);

            $extensionCollection->set($plugin->getName(), $extension);
        }

        if ($extensionCollection->count() === 0) {
            return [];
        }

        try {
            // Create a SystemSource context - required for Store API access without user auth
            $systemContext = new Context(new SystemSource());

            // Use Shopware's StoreClient (handles caching, authentication, etc.)
            $updates = $this->storeClient->getExtensionUpdateList($extensionCollection, $systemContext);
            $this->lastUpdateCount = count($updates);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }

        $result = [];
        foreach ($updates as $update) {
            $result[$update->getName()] = $update->getVersion();
        }

        return $result;
    }

    /**
     * Get debug info from last check.
     *
     * @return array{error: ?string, update_count: ?int}
     */
    public function getDebugInfo(): array
    {
        return [
            'error' => $this->lastError,
            'update_count' => $this->lastUpdateCount,
        ];
    }
}
