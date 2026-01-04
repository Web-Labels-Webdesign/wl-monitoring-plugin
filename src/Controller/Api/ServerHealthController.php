<?php

declare(strict_types=1);

namespace WlMonitoring\Controller\Api;

use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use WlMonitoring\Service\ServerHealthCollector;

#[Route(defaults: ['_routeScope' => ['api']])]
class ServerHealthController extends AbstractController
{
    public function __construct(
        private readonly ServerHealthCollector $collector
    ) {
    }

    #[Route(
        path: '/api/wl-monitoring/server-health',
        name: 'api.wl_monitoring.server_health',
        methods: ['GET']
    )]
    public function getServerHealth(Context $context): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $this->collector->collect(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
