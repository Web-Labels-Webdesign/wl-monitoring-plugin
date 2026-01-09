<?php

declare(strict_types=1);

namespace WlMonitoring\Service;

use Doctrine\DBAL\Connection;

class SearchLogService
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Get comprehensive search analytics.
     *
     * @return array<string, mixed>
     */
    public function getAnalytics(): array
    {
        if (!$this->tableExists()) {
            return [
                'available' => false,
                'message' => 'Search logging not initialized. Run plugin migrations.',
            ];
        }

        try {
            return [
                'available' => true,
                'overview' => $this->getOverview(),
                'type_breakdown' => $this->getTypeBreakdown(),
                'top_searches' => $this->getTopSearches(),
                'failed_searches' => $this->getFailedSearches(),
                'trending' => $this->getTrendingSearches(),
                'hourly_distribution' => $this->getHourlyDistribution(),
            ];
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if the search log table exists.
     */
    private function tableExists(): bool
    {
        try {
            return (bool) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = 'wl_search_log'"
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get search overview statistics.
     *
     * @return array<string, mixed>
     */
    private function getOverview(): array
    {
        // Today's searches
        $today = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) as total_searches,
                COUNT(DISTINCT search_term) as unique_terms,
                COUNT(DISTINCT session_id) as unique_sessions,
                AVG(result_count) as avg_results,
                SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) as zero_results
            FROM wl_search_log
            WHERE DATE(created_at) = CURDATE()"
        );

        // This week
        $week = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) as total_searches,
                COUNT(DISTINCT search_term) as unique_terms,
                COUNT(DISTINCT session_id) as unique_sessions
            FROM wl_search_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        // This month
        $month = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) as total_searches,
                COUNT(DISTINCT search_term) as unique_terms
            FROM wl_search_log
            WHERE YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW())"
        );

        $totalToday = (int) ($today['total_searches'] ?? 0);
        $zeroResultsToday = (int) ($today['zero_results'] ?? 0);

        return [
            'today' => [
                'total_searches' => $totalToday,
                'unique_terms' => (int) ($today['unique_terms'] ?? 0),
                'unique_sessions' => (int) ($today['unique_sessions'] ?? 0),
                'avg_results' => round((float) ($today['avg_results'] ?? 0), 1),
                'zero_result_rate' => $totalToday > 0
                    ? round(($zeroResultsToday / $totalToday) * 100, 1)
                    : 0,
            ],
            'this_week' => [
                'total_searches' => (int) ($week['total_searches'] ?? 0),
                'unique_terms' => (int) ($week['unique_terms'] ?? 0),
                'unique_sessions' => (int) ($week['unique_sessions'] ?? 0),
            ],
            'this_month' => [
                'total_searches' => (int) ($month['total_searches'] ?? 0),
                'unique_terms' => (int) ($month['unique_terms'] ?? 0),
            ],
        ];
    }

    /**
     * Get search type breakdown (search vs suggest).
     *
     * @return array<string, mixed>
     */
    private function getTypeBreakdown(): array
    {
        // Check if search_type column exists (for backwards compatibility)
        $columnExists = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE()
            AND table_name = 'wl_search_log'
            AND column_name = 'search_type'"
        );

        if (!$columnExists) {
            return [
                'available' => false,
                'message' => 'Run migrations to enable search type tracking.',
            ];
        }

        // Today's breakdown
        $today = $this->connection->fetchAllAssociative(
            "SELECT
                search_type,
                COUNT(*) as count,
                COUNT(DISTINCT search_term) as unique_terms,
                SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) as zero_results
            FROM wl_search_log
            WHERE DATE(created_at) = CURDATE()
            GROUP BY search_type"
        );

        // This week's breakdown
        $week = $this->connection->fetchAllAssociative(
            "SELECT
                search_type,
                COUNT(*) as count,
                COUNT(DISTINCT search_term) as unique_terms
            FROM wl_search_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY search_type"
        );

        // Convert to associative arrays
        $todayByType = [];
        foreach ($today as $row) {
            $type = $row['search_type'] ?? 'search';
            $count = (int) ($row['count'] ?? 0);
            $zeroResults = (int) ($row['zero_results'] ?? 0);
            $todayByType[$type] = [
                'count' => $count,
                'unique_terms' => (int) ($row['unique_terms'] ?? 0),
                'zero_result_rate' => $count > 0
                    ? round(($zeroResults / $count) * 100, 1)
                    : 0,
            ];
        }

        $weekByType = [];
        foreach ($week as $row) {
            $type = $row['search_type'] ?? 'search';
            $weekByType[$type] = [
                'count' => (int) ($row['count'] ?? 0),
                'unique_terms' => (int) ($row['unique_terms'] ?? 0),
            ];
        }

        return [
            'available' => true,
            'today' => [
                'search' => $todayByType['search'] ?? ['count' => 0, 'unique_terms' => 0, 'zero_result_rate' => 0],
                'suggest' => $todayByType['suggest'] ?? ['count' => 0, 'unique_terms' => 0, 'zero_result_rate' => 0],
            ],
            'this_week' => [
                'search' => $weekByType['search'] ?? ['count' => 0, 'unique_terms' => 0],
                'suggest' => $weekByType['suggest'] ?? ['count' => 0, 'unique_terms' => 0],
            ],
        ];
    }

    /**
     * Get top searched terms.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getTopSearches(int $limit = 20): array
    {
        $results = $this->connection->fetchAllAssociative(
            "SELECT
                search_term,
                COUNT(*) as search_count,
                AVG(result_count) as avg_results,
                SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) as zero_results
            FROM wl_search_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY search_term
            ORDER BY search_count DESC
            LIMIT :limit",
            ['limit' => $limit],
            ['limit' => \PDO::PARAM_INT]
        );

        return array_map(function ($row) {
            $count = (int) ($row['search_count'] ?? 0);
            $zeroResults = (int) ($row['zero_results'] ?? 0);

            return [
                'term' => $row['search_term'] ?? '',
                'count' => $count,
                'avg_results' => round((float) ($row['avg_results'] ?? 0), 1),
                'zero_result_rate' => $count > 0
                    ? round(($zeroResults / $count) * 100, 1)
                    : 0,
            ];
        }, $results);
    }

    /**
     * Get searches with no results (failed searches).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getFailedSearches(int $limit = 20): array
    {
        $results = $this->connection->fetchAllAssociative(
            "SELECT
                search_term,
                COUNT(*) as search_count,
                MAX(created_at) as last_searched
            FROM wl_search_log
            WHERE result_count = 0
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY search_term
            ORDER BY search_count DESC
            LIMIT :limit",
            ['limit' => $limit],
            ['limit' => \PDO::PARAM_INT]
        );

        return array_map(function ($row) {
            return [
                'term' => $row['search_term'] ?? '',
                'count' => (int) ($row['search_count'] ?? 0),
                'last_searched' => $row['last_searched'] ?? null,
            ];
        }, $results);
    }

    /**
     * Get trending searches (comparing today vs yesterday).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getTrendingSearches(int $limit = 10): array
    {
        // Get today's searches with their counts
        $today = $this->connection->fetchAllAssociative(
            "SELECT search_term, COUNT(*) as count
            FROM wl_search_log
            WHERE DATE(created_at) = CURDATE()
            GROUP BY search_term
            ORDER BY count DESC
            LIMIT 50"
        );

        // Get yesterday's counts for the same terms
        $todayTerms = array_column($today, 'search_term');
        if (empty($todayTerms)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($todayTerms), '?'));
        $yesterday = $this->connection->fetchAllKeyValue(
            "SELECT search_term, COUNT(*) as count
            FROM wl_search_log
            WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
            AND search_term IN ({$placeholders})
            GROUP BY search_term",
            $todayTerms
        );

        // Calculate growth and sort
        $trending = [];
        foreach ($today as $row) {
            $term = $row['search_term'];
            $todayCount = (int) $row['count'];
            $yesterdayCount = (int) ($yesterday[$term] ?? 0);

            // Calculate growth (handle division by zero)
            $growth = $yesterdayCount > 0
                ? (($todayCount - $yesterdayCount) / $yesterdayCount) * 100
                : ($todayCount > 0 ? 100 : 0); // New term = 100% growth

            $trending[] = [
                'term' => $term,
                'today_count' => $todayCount,
                'yesterday_count' => $yesterdayCount,
                'growth_percent' => round($growth, 1),
            ];
        }

        // Sort by growth percentage
        usort($trending, fn ($a, $b) => $b['growth_percent'] <=> $a['growth_percent']);

        return array_slice($trending, 0, $limit);
    }

    /**
     * Get hourly distribution of searches (last 24 hours).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getHourlyDistribution(): array
    {
        $results = $this->connection->fetchAllAssociative(
            "SELECT
                HOUR(created_at) as hour,
                COUNT(*) as search_count
            FROM wl_search_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY HOUR(created_at)
            ORDER BY hour"
        );

        // Fill in missing hours with zero
        $distribution = array_fill(0, 24, 0);
        foreach ($results as $row) {
            $distribution[(int) $row['hour']] = (int) $row['search_count'];
        }

        return array_map(function ($count, $hour) {
            return [
                'hour' => $hour,
                'count' => $count,
            ];
        }, $distribution, array_keys($distribution));
    }

    /**
     * Clean up old search logs (older than 90 days).
     *
     * @return int Number of deleted rows
     */
    public function cleanup(int $daysToKeep = 90): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'DELETE FROM wl_search_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)',
            ['days' => $daysToKeep],
            ['days' => \PDO::PARAM_INT]
        );
    }
}
