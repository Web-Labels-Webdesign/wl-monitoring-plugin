<?php

declare(strict_types=1);

namespace WlMonitoring\Controller\Api;

use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use WlMonitoring\Service\CacheInfoCollector;

#[Route(defaults: ['_routeScope' => ['api']])]
class CacheController extends AbstractController
{
    public function __construct(
        private readonly CacheInfoCollector $collector
    ) {
    }

    #[Route(
        path: '/api/wl-monitoring/cache',
        name: 'api.wl_monitoring.cache',
        methods: ['GET']
    )]
    public function getCache(Context $context): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $this->collector->collect(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
