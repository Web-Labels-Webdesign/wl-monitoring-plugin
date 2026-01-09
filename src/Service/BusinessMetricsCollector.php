<?php

declare(strict_types=1);

namespace WlMonitoring\Service;

use Doctrine\DBAL\Connection;

class BusinessMetricsCollector
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SearchLogService $searchLogService
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
            'revenue_overview' => $this->getRevenueOverview(),
            'revenue_by_payment' => $this->getRevenueByPaymentMethod(),
            'revenue_by_manufacturer' => $this->getRevenueByManufacturer(),
            'revenue_by_category' => $this->getRevenueByCategory(),
            'refunds' => $this->getRefundStats(),
            'search_analytics' => $this->getSearchAnalytics(),
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

    /**
     * Revenue overview with current/last month/year, growth rate, forecast, and AOV.
     *
     * @return array<string, mixed>
     */
    private function getRevenueOverview(): array
    {
        try {
            $now = new \DateTimeImmutable();
            $currentYear = (int) $now->format('Y');
            $currentMonth = (int) $now->format('m');
            $lastYear = $currentYear - 1;
            $lastMonth = $currentMonth === 1 ? 12 : $currentMonth - 1;
            $lastMonthYear = $currentMonth === 1 ? $lastYear : $currentYear;

            // Current month revenue and orders
            $currentMonthData = $this->connection->fetchAssociative(
                "SELECT
                    COALESCE(SUM(amount_total), 0) as revenue,
                    COALESCE(SUM(amount_net), 0) as revenue_net,
                    COUNT(*) as order_count
                FROM `order`
                WHERE YEAR(created_at) = :year AND MONTH(created_at) = :month",
                ['year' => $currentYear, 'month' => $currentMonth]
            );

            // Last month
            $lastMonthData = $this->connection->fetchAssociative(
                "SELECT
                    COALESCE(SUM(amount_total), 0) as revenue,
                    COALESCE(SUM(amount_net), 0) as revenue_net,
                    COUNT(*) as order_count
                FROM `order`
                WHERE YEAR(created_at) = :year AND MONTH(created_at) = :month",
                ['year' => $lastMonthYear, 'month' => $lastMonth]
            );

            // Current year
            $currentYearData = $this->connection->fetchAssociative(
                "SELECT
                    COALESCE(SUM(amount_total), 0) as revenue,
                    COALESCE(SUM(amount_net), 0) as revenue_net,
                    COUNT(*) as order_count
                FROM `order`
                WHERE YEAR(created_at) = :year",
                ['year' => $currentYear]
            );

            // Last year
            $lastYearData = $this->connection->fetchAssociative(
                "SELECT
                    COALESCE(SUM(amount_total), 0) as revenue,
                    COALESCE(SUM(amount_net), 0) as revenue_net,
                    COUNT(*) as order_count
                FROM `order`
                WHERE YEAR(created_at) = :year",
                ['year' => $lastYear]
            );

            $currentMonthRevenue = (float) ($currentMonthData['revenue'] ?? 0);
            $currentMonthOrders = (int) ($currentMonthData['order_count'] ?? 0);
            $lastMonthRevenue = (float) ($lastMonthData['revenue'] ?? 0);

            // Growth rate (month over month)
            $growthRate = $lastMonthRevenue > 0
                ? (($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100
                : 0;

            // Forecast: extrapolate current month based on days elapsed
            $dayOfMonth = (int) $now->format('j');
            $daysInMonth = (int) $now->format('t');
            $forecast = $dayOfMonth > 0
                ? ($currentMonthRevenue / $dayOfMonth) * $daysInMonth
                : 0;

            // Average order value
            $aov = $currentMonthOrders > 0
                ? $currentMonthRevenue / $currentMonthOrders
                : 0;

            return [
                'current_month' => round($currentMonthRevenue, 2),
                'current_month_net' => round((float) ($currentMonthData['revenue_net'] ?? 0), 2),
                'current_month_orders' => $currentMonthOrders,
                'last_month' => round($lastMonthRevenue, 2),
                'last_month_net' => round((float) ($lastMonthData['revenue_net'] ?? 0), 2),
                'last_month_orders' => (int) ($lastMonthData['order_count'] ?? 0),
                'current_year' => round((float) ($currentYearData['revenue'] ?? 0), 2),
                'current_year_net' => round((float) ($currentYearData['revenue_net'] ?? 0), 2),
                'current_year_orders' => (int) ($currentYearData['order_count'] ?? 0),
                'last_year' => round((float) ($lastYearData['revenue'] ?? 0), 2),
                'last_year_net' => round((float) ($lastYearData['revenue_net'] ?? 0), 2),
                'last_year_orders' => (int) ($lastYearData['order_count'] ?? 0),
                'growth_rate_monthly' => round($growthRate, 1),
                'forecast_month_end' => round($forecast, 2),
                'average_order_value' => round($aov, 2),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Top 10 payment methods by revenue.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getRevenueByPaymentMethod(): array
    {
        try {
            $sql = "
                SELECT
                    COALESCE(pmt.name, pm.id, 'Unknown') as payment_method,
                    COUNT(DISTINCT o.id) as order_count,
                    COALESCE(SUM(o.amount_total), 0) as total_revenue
                FROM `order` o
                INNER JOIN order_transaction ot ON o.id = ot.order_id AND ot.order_version_id = o.version_id
                INNER JOIN payment_method pm ON ot.payment_method_id = pm.id
                LEFT JOIN payment_method_translation pmt ON pm.id = pmt.payment_method_id
                WHERE YEAR(o.created_at) = YEAR(NOW()) AND MONTH(o.created_at) = MONTH(NOW())
                GROUP BY pm.id, pmt.name
                ORDER BY total_revenue DESC
                LIMIT 10
            ";

            $results = $this->connection->fetchAllAssociative($sql);
            $totalRevenue = array_sum(array_column($results, 'total_revenue'));

            return array_map(function ($row) use ($totalRevenue) {
                $revenue = (float) ($row['total_revenue'] ?? 0);
                return [
                    'name' => $row['payment_method'] ?? 'Unknown',
                    'order_count' => (int) ($row['order_count'] ?? 0),
                    'revenue' => round($revenue, 2),
                    'percentage' => $totalRevenue > 0 ? round(($revenue / $totalRevenue) * 100, 1) : 0,
                ];
            }, $results);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Top 10 manufacturers by revenue.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getRevenueByManufacturer(): array
    {
        try {
            $sql = "
                SELECT
                    COALESCE(pmt.name, 'No Manufacturer') as manufacturer_name,
                    SUM(oli.quantity) as product_count,
                    COALESCE(SUM(oli.total_price), 0) as total_revenue,
                    COUNT(DISTINCT o.id) as order_count
                FROM order_line_item oli
                INNER JOIN `order` o ON oli.order_id = o.id AND oli.order_version_id = o.version_id
                LEFT JOIN product p ON oli.product_id = p.id
                LEFT JOIN product_manufacturer pm ON p.product_manufacturer_id = pm.id
                LEFT JOIN product_manufacturer_translation pmt ON pm.id = pmt.product_manufacturer_id
                WHERE YEAR(o.created_at) = YEAR(NOW()) AND MONTH(o.created_at) = MONTH(NOW())
                AND oli.type = 'product'
                GROUP BY pm.id, pmt.name
                ORDER BY total_revenue DESC
                LIMIT 10
            ";

            $results = $this->connection->fetchAllAssociative($sql);

            return array_map(function ($row) {
                return [
                    'name' => $row['manufacturer_name'] ?? 'No Manufacturer',
                    'product_count' => (int) ($row['product_count'] ?? 0),
                    'revenue' => round((float) ($row['total_revenue'] ?? 0), 2),
                    'order_count' => (int) ($row['order_count'] ?? 0),
                ];
            }, $results);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Top 10 categories by revenue.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getRevenueByCategory(): array
    {
        try {
            $sql = "
                SELECT
                    COALESCE(ct.name, 'Uncategorized') as category_name,
                    SUM(oli.quantity) as product_count,
                    COALESCE(SUM(oli.total_price), 0) as total_revenue,
                    COUNT(DISTINCT o.id) as order_count
                FROM order_line_item oli
                INNER JOIN `order` o ON oli.order_id = o.id AND oli.order_version_id = o.version_id
                LEFT JOIN product p ON oli.product_id = p.id
                LEFT JOIN product_category pc ON p.id = pc.product_id
                LEFT JOIN category c ON pc.category_id = c.id
                LEFT JOIN category_translation ct ON c.id = ct.category_id
                WHERE YEAR(o.created_at) = YEAR(NOW()) AND MONTH(o.created_at) = MONTH(NOW())
                AND oli.type = 'product'
                AND c.id IS NOT NULL
                GROUP BY c.id, ct.name
                ORDER BY total_revenue DESC
                LIMIT 10
            ";

            $results = $this->connection->fetchAllAssociative($sql);

            return array_map(function ($row) {
                return [
                    'name' => $row['category_name'] ?? 'Uncategorized',
                    'product_count' => (int) ($row['product_count'] ?? 0),
                    'revenue' => round((float) ($row['total_revenue'] ?? 0), 2),
                    'order_count' => (int) ($row['order_count'] ?? 0),
                ];
            }, $results);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Refund and cancellation statistics.
     *
     * @return array<string, mixed>
     */
    private function getRefundStats(): array
    {
        try {
            // Cancelled orders this month
            $cancelledSql = "
                SELECT
                    COUNT(DISTINCT o.id) as count,
                    COALESCE(SUM(o.amount_total), 0) as amount
                FROM `order` o
                INNER JOIN state_machine_state sms ON o.state_id = sms.id
                WHERE YEAR(o.created_at) = YEAR(NOW()) AND MONTH(o.created_at) = MONTH(NOW())
                AND sms.technical_name = 'cancelled'
            ";

            $cancelled = $this->connection->fetchAssociative($cancelledSql);

            // Refunded transactions this month
            $refundedSql = "
                SELECT
                    COUNT(DISTINCT o.id) as count,
                    COALESCE(SUM(o.amount_total), 0) as amount
                FROM `order` o
                INNER JOIN order_transaction ot ON o.id = ot.order_id AND ot.order_version_id = o.version_id
                INNER JOIN state_machine_state ots ON ot.state_id = ots.id
                WHERE YEAR(o.created_at) = YEAR(NOW()) AND MONTH(o.created_at) = MONTH(NOW())
                AND ots.technical_name IN ('refunded', 'refunded_partially')
            ";

            $refunded = $this->connection->fetchAssociative($refundedSql);

            // Total orders this month for rate calculation
            $totalOrders = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM `order`
                WHERE YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW())"
            );

            $cancelledCount = (int) ($cancelled['count'] ?? 0);
            $refundedCount = (int) ($refunded['count'] ?? 0);

            return [
                'cancelled_orders_count' => $cancelledCount,
                'cancelled_orders_value' => round((float) ($cancelled['amount'] ?? 0), 2),
                'refunded_orders_count' => $refundedCount,
                'refunded_orders_value' => round((float) ($refunded['amount'] ?? 0), 2),
                'cancellation_rate' => $totalOrders > 0 ? round(($cancelledCount / $totalOrders) * 100, 1) : 0,
                'refund_rate' => $totalOrders > 0 ? round(($refundedCount / $totalOrders) * 100, 1) : 0,
                'total_orders_this_month' => $totalOrders,
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Search analytics - top searches and failed searches.
     * Uses the SearchLogService which logs actual user searches.
     *
     * @return array<string, mixed>
     */
    private function getSearchAnalytics(): array
    {
        return $this->searchLogService->getAnalytics();
    }
}
