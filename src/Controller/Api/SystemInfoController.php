<?php

declare(strict_types=1);

namespace WlMonitoring\Controller\Api;

use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use WlMonitoring\Service\SystemInfoCollector;

#[Route(defaults: ['_routeScope' => ['api']])]
class SystemInfoController extends AbstractController
{
    public function __construct(
        private readonly SystemInfoCollector $collector
    ) {
    }

    #[Route(
        path: '/api/wl-monitoring/system',
        name: 'api.wl_monitoring.system',
        methods: ['GET']
    )]
    public function getSystemInfo(Context $context): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $this->collector->collect(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
