# WlMonitoring - Shopware 6 Monitoring Plugin

Comprehensive monitoring plugin for Shopware 6 that provides system, security, performance, logs, cache, and business metrics via API endpoints.

## Requirements

- Shopware 6.5.0 or higher (< 6.8.0)
- PHP 8.1 or higher

## Installation

### Via Composer

```bash
composer require web-labels/wl-monitoring
bin/console plugin:refresh
bin/console plugin:install --activate WlMonitoring
```

### Manual Installation

1. Download the latest release (`WlMonitoring.zip`) from [Releases](https://github.com/Web-Labels-Webdesign/wl-monitoring-plugin/releases)
2. Upload to your Shopware installation's `custom/plugins/` directory
3. Extract the zip file
4. Run:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate WlMonitoring
   ```

## API Endpoints

All endpoints require Admin API authentication and are available under `/api/wl-monitoring/`:

| Endpoint | Description |
|----------|-------------|
| `/api/wl-monitoring/system` | System info (PHP, MySQL, Server, Shopware version) |
| `/api/wl-monitoring/plugins` | Installed plugins and apps with update info |
| `/api/wl-monitoring/health` | Lightweight health check |
| `/api/wl-monitoring/security` | Security status and advisories |
| `/api/wl-monitoring/cache` | Cache pool statistics |
| `/api/wl-monitoring/logs` | Log file information |
| `/api/wl-monitoring/queue` | Message queue and scheduled tasks |
| `/api/wl-monitoring/performance` | Performance metrics |
| `/api/wl-monitoring/elasticsearch` | Elasticsearch cluster info |
| `/api/wl-monitoring/business` | Business metrics (orders, revenue, customers) |

## Usage with Monitoring App

This plugin is designed to work with the [Web-Labels Monitoring App](https://github.com/Web-Labels-Webdesign/monitoring). Connect your Shopware shop using Admin API credentials to enable full monitoring capabilities.

## License

MIT License - see [LICENSE](LICENSE) for details.
