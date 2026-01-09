<?php

declare(strict_types=1);

namespace WlMonitoring\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1736450000SearchLog extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1736450000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `wl_search_log` (
                `id` BINARY(16) NOT NULL,
                `search_term` VARCHAR(255) NOT NULL,
                `sales_channel_id` BINARY(16) NULL,
                `result_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `customer_id` BINARY(16) NULL,
                `session_id` VARCHAR(128) NULL,
                `ip_hash` VARCHAR(64) NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_search_term` (`search_term`),
                INDEX `idx_sales_channel` (`sales_channel_id`),
                INDEX `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
