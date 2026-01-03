<?php

declare(strict_types=1);

namespace WlMonitoring\Controller\Api;

use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use WlMonitoring\Service\PluginInfoCollector;

#[Route(defaults: ['_routeScope' => ['api']])]
class PluginInfoController extends AbstractController
{
    public function __construct(
        private readonly PluginInfoCollector $collector
    ) {
    }

    #[Route(
        path: '/api/wl-monitoring/plugins',
        name: 'api.wl_monitoring.plugins',
        methods: ['GET']
    )]
    public function getPluginInfo(Context $context): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $this->collector->collect($context),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
