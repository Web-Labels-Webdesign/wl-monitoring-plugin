<?php

declare(strict_types=1);

namespace WlMonitoring\Controller\Api;

use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use WlMonitoring\Service\QueueInfoCollector;

#[Route(defaults: ['_routeScope' => ['api']])]
class QueueInfoController extends AbstractController
{
    public function __construct(
        private readonly QueueInfoCollector $collector
    ) {
    }

    #[Route(
        path: '/api/wl-monitoring/queue',
        name: 'api.wl_monitoring.queue',
        methods: ['GET']
    )]
    public function getQueueInfo(Context $context): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $this->collector->collect($context),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
