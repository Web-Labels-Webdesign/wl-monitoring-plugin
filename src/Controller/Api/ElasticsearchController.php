<?php

declare(strict_types=1);

namespace WlMonitoring\Controller\Api;

use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use WlMonitoring\Service\ElasticsearchInfoCollector;

#[Route(defaults: ['_routeScope' => ['api']])]
class ElasticsearchController extends AbstractController
{
    public function __construct(
        private readonly ElasticsearchInfoCollector $collector
    ) {
    }

    #[Route(
        path: '/api/wl-monitoring/elasticsearch',
        name: 'api.wl_monitoring.elasticsearch',
        methods: ['GET']
    )]
    public function getElasticsearch(Context $context): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $this->collector->collect(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
