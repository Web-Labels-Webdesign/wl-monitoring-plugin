<?php

declare(strict_types=1);

namespace WlMonitoring;

use Shopware\Core\Framework\Plugin;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class WlMonitoring extends Plugin
{
    public function configureRoutes(RoutingConfigurator $routes, string $environment): void
    {
        $routes->import(__DIR__ . '/Resources/config/routes.xml');
    }
}
