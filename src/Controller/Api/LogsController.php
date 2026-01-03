<?php

declare(strict_types=1);

namespace WlMonitoring\Controller\Api;

use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use WlMonitoring\Service\LogInfoCollector;

#[Route(defaults: ['_routeScope' => ['api']])]
class LogsController extends AbstractController
{
    public function __construct(
        private readonly LogInfoCollector $collector
    ) {
    }

    #[Route(
        path: '/api/wl-monitoring/logs',
        name: 'api.wl_monitoring.logs',
        methods: ['GET']
    )]
    public function getLogs(Context $context): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $this->collector->collect(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
