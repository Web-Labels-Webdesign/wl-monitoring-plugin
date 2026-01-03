<?php

declare(strict_types=1);

namespace WlMonitoring\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Increment\IncrementGatewayRegistry;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskEntity;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

class QueueInfoCollector
{
    /**
     * @param ServiceLocator<ReceiverInterface> $transportLocator
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $scheduledTaskRepository,
        private readonly IncrementGatewayRegistry $incrementGateway,
        private readonly ServiceLocator $transportLocator
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(Context $context): array
    {
        return [
            'message_queue' => $this->getMessageQueueStats(),
            'transports' => $this->getTransportStats(),
            'scheduled_tasks' => $this->getScheduledTasks($context),
        ];
    }

    /**
     * Lightweight queue health check
     *
     * @return array<string, mixed>
     */
    public function getHealthData(): array
    {
        try {
            $pending = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE delivered_at IS NULL'
            );

            $failed = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM dead_message'
            );

            return [
                'queue_pending' => $pending,
                'queue_failed' => $failed,
                'queue_ok' => true,
            ];
        } catch (\Throwable) {
            return [
                'queue_pending' => null,
                'queue_failed' => null,
                'queue_ok' => false,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getMessageQueueStats(): array
    {
        try {
            // Count pending messages in messenger_messages table
            $pending = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE delivered_at IS NULL'
            );

            // Count failed messages
            $failed = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM dead_message'
            );

            return [
                'pending' => $pending,
                'failed' => $failed,
            ];
        } catch (\Throwable $e) {
            // Table might not exist or other error
            return [
                'pending' => null,
                'failed' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get stats from all Messenger transports (Redis, database, etc.)
     *
     * @return array<int, array<string, mixed>>
     */
    private function getTransportStats(): array
    {
        $stats = [];

        // Get incrementer stats (Shopware's internal queue tracking)
        try {
            $incrementer = $this->incrementGateway->get(IncrementGatewayRegistry::MESSAGE_QUEUE_POOL);
            $list = $incrementer->list('message_queue_stats', -1);

            foreach ($list as $entry) {
                $stats[] = [
                    'name' => $entry['key'],
                    'size' => (int) $entry['count'],
                    'source' => 'incrementer',
                ];
            }
        } catch (\Throwable) {
            // Incrementer not available
        }

        // Get Messenger transport stats (catches Redis transports)
        foreach ($this->getTransportNames() as $transportName) {
            if (!$this->transportLocator->has($transportName)) {
                continue;
            }

            try {
                $transport = $this->transportLocator->get($transportName);
                if (!$transport instanceof MessageCountAwareInterface) {
                    continue;
                }

                $stats[] = [
                    'name' => str_replace('messenger.transport.', '', $transportName),
                    'size' => $transport->getMessageCount(),
                    'source' => 'transport',
                ];
            } catch (\Throwable) {
                // Transport not available or error getting count
                continue;
            }
        }

        usort($stats, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return $stats;
    }

    /**
     * @return array<string>
     */
    private function getTransportNames(): array
    {
        try {
            $names = array_keys($this->transportLocator->getProvidedServices());

            return array_filter($names, static fn (string $n): bool => str_starts_with($n, 'messenger.transport'));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getScheduledTasks(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));

        $tasks = $this->scheduledTaskRepository->search($criteria, $context);

        $result = [];
        /** @var ScheduledTaskEntity $task */
        foreach ($tasks as $task) {
            $result[] = [
                'name' => $task->getName(),
                'status' => $task->getStatus(),
                'run_interval' => $task->getRunInterval(),
                'next_execution_time' => $task->getNextExecutionTime()?->format(\DateTimeInterface::ATOM),
                'last_execution_time' => $task->getLastExecutionTime()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $result;
    }
}
