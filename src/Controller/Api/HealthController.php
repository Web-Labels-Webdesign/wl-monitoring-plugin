<?php

declare(strict_types=1);

namespace WlMonitoring\Controller\Api;

use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use WlMonitoring\Service\QueueInfoCollector;
use WlMonitoring\Service\SystemInfoCollector;

#[Route(defaults: ['_routeScope' => ['api']])]
class HealthController extends AbstractController
{
    public function __construct(
        private readonly SystemInfoCollector $systemInfoCollector,
        private readonly QueueInfoCollector $queueInfoCollector
    ) {
    }

    #[Route(
        path: '/api/wl-monitoring/health',
        name: 'api.wl_monitoring.health',
        methods: ['GET']
    )]
    public function getHealth(Context $context): JsonResponse
    {
        $systemHealth = $this->systemInfoCollector->getHealthData();
        $queueHealth = $this->queueInfoCollector->getHealthData();

        $healthy = $systemHealth['mysql_connection'] && $queueHealth['queue_ok'];

        return new JsonResponse([
            'success' => true,
            'healthy' => $healthy,
            'data' => [
                'system' => $systemHealth,
                'queue' => $queueHealth,
            ],
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $healthy ? 200 : 503);
    }
}
