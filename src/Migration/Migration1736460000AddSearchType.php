<?php

declare(strict_types=1);

namespace WlMonitoring\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1736460000AddSearchType extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1736460000;
    }

    public function update(Connection $connection): void
    {
        // Check if column already exists
        $columnExists = $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
            AND table_name = 'wl_search_log'
            AND column_name = 'search_type'"
        );

        if (!$columnExists) {
            $connection->executeStatement("
                ALTER TABLE `wl_search_log`
                ADD COLUMN `search_type` ENUM('search', 'suggest') NOT NULL DEFAULT 'search' AFTER `search_term`,
                ADD INDEX `idx_search_type` (`search_type`)
            ");
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
