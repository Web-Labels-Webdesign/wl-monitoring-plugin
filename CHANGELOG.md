# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.4.0] - 2026-01-09

### Added
- **Search Analytics**: Real-time logging of customer searches
  - New `wl_search_log` database table via migration
  - `SearchLogSubscriber` captures ProductSearchResultEvent
  - `SearchLogService` provides comprehensive analytics:
    - Top searches with result counts
    - Failed searches (zero results) for product gap analysis
    - Trending searches (day-over-day comparison)
    - Hourly search distribution
    - Zero-result rate tracking
  - Privacy-conscious: IP hashes (not stored), session-based tracking

### Changed
- `BusinessMetricsCollector` now uses `SearchLogService` for actual user search data instead of indexed keywords

## [2.3.0] - 2026-01-09

### Added
- Extended business metrics with comprehensive revenue analytics:
  - Revenue overview: current/last month/year with growth rate and forecast
  - Revenue by payment method (top 10 with percentages)
  - Revenue by manufacturer (top 10 with order counts)
  - Revenue by category (top 10 with order counts)
  - Refund and cancellation statistics with rates
  - Search analytics placeholder (indexed keywords)

## [2.2.1] - 2026-01-04

### Changed
- Hardcoded signature max age to 300 seconds (5 minutes) - removed from user configuration

## [2.2.0] - 2026-01-04

### Added
- Redis deep metrics: ops/sec, hit rate, memory fragmentation, blocked clients, evicted/expired keys
- Elasticsearch deep metrics: JVM heap usage, shard status, query/indexing totals, cluster stats

### Changed
- CacheInfoCollector now returns comprehensive Redis performance data
- ElasticsearchInfoCollector now returns cluster-wide statistics and node metrics

## [2.1.0] - 2026-01-04

### Added
- Server health endpoint (`/api/wl-monitoring/server-health`) with:
  - CPU load average (1m, 5m, 15m) and core count
  - PHP-FPM pool statistics (active/idle processes, listen queue, etc.)
  - MySQL deep stats (threads, slow queries, InnoDB buffer pool metrics)

## [2.0.2] - 2026-01-04

### Changed
- Increased maximum recent errors from 20 to 100 for better error history coverage

## [2.0.1] - 2026-01-04

### Fixed
- Recent errors now correctly shows errors from the last 24 hours instead of only from the last 1000 log lines
- Error count in "Errors (24h)" now matches the entries shown in "Recent Errors" when there are 20 or fewer errors

## [2.0.0] - 2026-01-04

### Added
- Ed25519 request signature verification
- Plugin configuration UI for public key and signature settings

### Changed
- **BREAKING**: All API requests now require valid signatures
- Public key must be configured before plugin API can be used

### Security
- Cryptographic signature verification prevents unauthorized API access
- Request timestamp validation prevents replay attacks

## [1.1.4] - 2026-01-03

### Changed
- Simplified plugin collection method

## [1.1.3] - 2026-01-02

### Added
- Redis cache monitoring
- Message queue monitoring
- Elasticsearch cluster health monitoring

### Changed
- Improved system info collector

## [1.1.0] - 2026-01-01

### Added
- Business metrics endpoint (orders, revenue, customers)
- Performance monitoring endpoint
- Log statistics endpoint
- Cache pool monitoring

## [1.0.0] - 2025-12-15

### Added
- Initial release
- System info endpoint (PHP, MySQL, Server)
- Plugin inventory with update detection
- Security status monitoring
- Health check endpoint
