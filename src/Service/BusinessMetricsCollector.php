<?php

declare(strict_types=1);

namespace WlMonitoring\Service;

use Doctrine\DBAL\Connection;

class BusinessMetricsCollector
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
            'orders' => $this->getOrderStats(),
            'customers' => $this->getCustomerStats(),
            'products' => $this->getProductStats(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getOrderStats(): array
    {
        try {
            $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `order`');

            $today = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM `order` WHERE DATE(created_at) = CURDATE()'
            );

            $last7Days = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM `order` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
            );

            $last30Days = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM `order` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
            );

            // Get revenue for different periods
            $revenueToday = $this->connection->fetchOne(
                'SELECT COALESCE(SUM(amount_total), 0) FROM `order` WHERE DATE(created_at) = CURDATE()'
            );

            $revenueLast7Days = $this->connection->fetchOne(
                'SELECT COALESCE(SUM(amount_total), 0) FROM `order` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
            );

            $revenueLast30Days = $this->connection->fetchOne(
                'SELECT COALESCE(SUM(amount_total), 0) FROM `order` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
            );

            return [
                'total' => $total,
                'today' => $today,
                'last_7_days' => $last7Days,
                'last_30_days' => $last30Days,
                'revenue_today' => round((float) $revenueToday, 2),
                'revenue_last_7_days' => round((float) $revenueLast7Days, 2),
                'revenue_last_30_days' => round((float) $revenueLast30Days, 2),
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getCustomerStats(): array
    {
        try {
            $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM customer');

            // Active = logged in within last 30 days
            $active = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM customer WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
            );

            // New customers this month
            $newThisMonth = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM customer WHERE created_at >= DATE_FORMAT(NOW(), \'%Y-%m-01\')'
            );

            // Guest vs registered
            $guests = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM customer WHERE guest = 1'
            );

            return [
                'total' => $total,
                'active' => $active,
                'new_this_month' => $newThisMonth,
                'registered' => $total - $guests,
                'guests' => $guests,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getProductStats(): array
    {
        try {
            $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM product WHERE parent_id IS NULL');

            // Active products (visible in storefront)
            $active = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM product WHERE parent_id IS NULL AND active = 1'
            );

            // Out of stock (stock <= 0 and clearance sale disabled)
            $outOfStock = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM product WHERE parent_id IS NULL AND active = 1 AND stock <= 0 AND is_closeout = 1'
            );

            // Low stock (stock between 1 and 5)
            $lowStock = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM product WHERE parent_id IS NULL AND active = 1 AND stock > 0 AND stock <= 5'
            );

            // Products with variants
            $withVariants = (int) $this->connection->fetchOne(
                'SELECT COUNT(DISTINCT parent_id) FROM product WHERE parent_id IS NOT NULL'
            );

            return [
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
                'out_of_stock' => $outOfStock,
                'low_stock' => $lowStock,
                'with_variants' => $withVariants,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}
