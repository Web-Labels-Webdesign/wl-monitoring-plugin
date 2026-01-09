<?php

declare(strict_types=1);

namespace WlMonitoring\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

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
        ];
    }

    public function onSearchResult(ProductSearchResultEvent $event): void
    {
        try {
            $request = $event->getRequest();
            $searchTerm = $request->query->get('search', '');

            // Skip empty searches or very short terms
            if (strlen($searchTerm) < 2) {
                return;
            }

            $result = $event->getResult();
            $context = $event->getSalesChannelContext();

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
                'sales_channel_id' => Uuid::fromHexToBytes($context->getSalesChannelId()),
                'result_count' => $result->getTotal(),
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
