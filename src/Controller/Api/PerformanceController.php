<?php

declare(strict_types=1);

namespace WlMonitoring\Controller\Api;

use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use WlMonitoring\Service\PerformanceInfoCollector;

#[Route(defaults: ['_routeScope' => ['api']])]
class PerformanceController extends AbstractController
{
    public function __construct(
        private readonly PerformanceInfoCollector $collector
    ) {
    }

    #[Route(
        path: '/api/wl-monitoring/performance',
        name: 'api.wl_monitoring.performance',
        methods: ['GET']
    )]
    public function getPerformance(Context $context): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $this->collector->collect(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
