<?php

declare(strict_types=1);

namespace WlMonitoring\Service;

use Shopware\Core\Framework\App\AppEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Plugin\PluginEntity;

class PluginInfoCollector
{
    public function __construct(
        private readonly EntityRepository $pluginRepository,
        private readonly EntityRepository $appRepository,
        private readonly PluginUpdateChecker $updateChecker
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(Context $context): array
    {
        return [
            'plugins' => $this->getPlugins($context),
            'apps' => $this->getApps($context),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getPlugins(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));

        $plugins = $this->pluginRepository->search($criteria, $context);

        // Fetch available updates from Shopware Store
        /** @var array<PluginEntity> $pluginEntities */
        $pluginEntities = $plugins->getElements();
        $availableUpdates = $this->updateChecker->checkForUpdates($pluginEntities, $context);

        $result = [];
        /** @var PluginEntity $plugin */
        foreach ($plugins as $plugin) {
            $pluginName = $plugin->getName();

            // Use Store API update info, fallback to database upgrade_version
            $upgradeVersion = $availableUpdates[$pluginName] ?? $plugin->getUpgradeVersion();

            $result[] = [
                'name' => $pluginName,
                'label' => $plugin->getLabel(),
                'version' => $plugin->getVersion(),
                'upgrade_version' => $upgradeVersion,
                'active' => $plugin->getActive(),
                'installed' => $plugin->getInstalledAt() !== null,
                'installed_at' => $plugin->getInstalledAt()?->format(\DateTimeInterface::ATOM),
                'upgraded_at' => $plugin->getUpgradedAt()?->format(\DateTimeInterface::ATOM),
                'author' => $plugin->getAuthor(),
                'composer_name' => $plugin->getComposerName(),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getApps(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));

        $apps = $this->appRepository->search($criteria, $context);

        $result = [];
        /** @var AppEntity $app */
        foreach ($apps as $app) {
            $result[] = [
                'name' => $app->getName(),
                'label' => $app->getLabel(),
                'version' => $app->getVersion(),
                'active' => $app->isActive(),
                'author' => $app->getAuthor(),
            ];
        }

        return $result;
    }
}
