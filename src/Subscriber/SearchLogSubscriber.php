<?php

declare(strict_types=1);

namespace WlMonitoring\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestResultEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;

class SearchLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductSearchResultEvent::class => 'onSearchResult',
            ProductSuggestResultEvent::class => 'onSuggestResult',
        ];
    }

    public function onSearchResult(ProductSearchResultEvent $event): void
    {
        $this->logSearch(
            $event->getRequest(),
            $event->getSalesChannelContext(),
            $event->getResult()->getTotal(),
            'search'
        );
    }

    public function onSuggestResult(ProductSuggestResultEvent $event): void
    {
        $this->logSearch(
            $event->getRequest(),
            $event->getSalesChannelContext(),
            $event->getResult()->getTotal(),
            'suggest'
        );
    }

    private function logSearch(
        Request $request,
        SalesChannelContext $context,
        int $resultCount,
        string $searchType
    ): void {
        try {
            $searchTerm = $request->query->get('search', '');

            // Skip empty searches or very short terms
            if (strlen($searchTerm) < 2) {
                return;
            }

            // Get customer ID if logged in
            $customer = $context->getCustomer();
            $customerId = $customer ? Uuid::fromHexToBytes($customer->getId()) : null;

            // Hash IP for privacy (we don't need the actual IP)
            $clientIp = $request->getClientIp();
            $ipHash = $clientIp ? hash('sha256', $clientIp . date('Y-m-d')) : null;

            // Get session ID (for tracking unique searches without identifying users)
            $session = $request->getSession();
            $sessionId = $session->isStarted() ? substr($session->getId(), 0, 32) : null;

            $this->connection->insert('wl_search_log', [
                'id' => Uuid::randomBytes(),
                'search_term' => mb_substr($searchTerm, 0, 255),
                'search_type' => $searchType,
                'sales_channel_id' => Uuid::fromHexToBytes($context->getSalesChannelId()),
                'result_count' => $resultCount,
                'customer_id' => $customerId,
                'session_id' => $sessionId,
                'ip_hash' => $ipHash,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]);
        } catch (\Throwable) {
            // Silently fail - logging should never break the shop
        }
    }
}
