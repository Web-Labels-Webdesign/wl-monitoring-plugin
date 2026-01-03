<?php

declare(strict_types=1);

namespace WlMonitoring\Controller\Api;

use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use WlMonitoring\Service\BusinessMetricsCollector;

#[Route(defaults: ['_routeScope' => ['api']])]
class BusinessMetricsController extends AbstractController
{
    public function __construct(
        private readonly BusinessMetricsCollector $collector
    ) {
    }

    #[Route(
        path: '/api/wl-monitoring/business',
        name: 'api.wl_monitoring.business',
        methods: ['GET']
    )]
    public function getBusinessMetrics(Context $context): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $this->collector->collect(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
