<?php

declare(strict_types=1);

namespace WlMonitoring\Controller\Api;

use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use WlMonitoring\Service\SecurityInfoCollector;

#[Route(defaults: ['_routeScope' => ['api']])]
class SecurityController extends AbstractController
{
    public function __construct(
        private readonly SecurityInfoCollector $collector
    ) {
    }

    #[Route(
        path: '/api/wl-monitoring/security',
        name: 'api.wl_monitoring.security',
        methods: ['GET']
    )]
    public function getSecurity(Context $context): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $this->collector->collect(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
