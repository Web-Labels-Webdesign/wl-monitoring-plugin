<?php

declare(strict_types=1);

namespace WlMonitoring\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use WlMonitoring\Service\SignatureVerificationService;

class SignatureVerificationSubscriber implements EventSubscriberInterface
{
    private const MONITORING_PATH_PREFIX = '/api/wl-monitoring/';

    private const SIGNATURE_HEADER = 'X-Monitoring-Signature';

    private const TIMESTAMP_HEADER = 'X-Monitoring-Timestamp';

    public function __construct(
        private readonly SignatureVerificationService $verificationService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Only process WlMonitoring API routes
        if (!str_starts_with($path, self::MONITORING_PATH_PREFIX)) {
            return;
        }

        // Check if public key is configured
        if (!$this->verificationService->hasPublicKey()) {
            $event->setResponse(new JsonResponse([
                'success' => false,
                'error' => 'Plugin not configured. Please add the monitoring public key in plugin settings.',
                'code' => 'NOT_CONFIGURED',
            ], Response::HTTP_SERVICE_UNAVAILABLE));

            return;
        }

        $signature = $request->headers->get(self::SIGNATURE_HEADER);
        $timestamp = $request->headers->get(self::TIMESTAMP_HEADER);

        // Check for required headers
        if (empty($signature) || empty($timestamp)) {
            $event->setResponse(new JsonResponse([
                'success' => false,
                'error' => 'Missing signature headers',
                'code' => 'SIGNATURE_MISSING',
            ], Response::HTTP_UNAUTHORIZED));

            return;
        }

        // Validate timestamp format
        if (!is_numeric($timestamp)) {
            $event->setResponse(new JsonResponse([
                'success' => false,
                'error' => 'Invalid timestamp format',
                'code' => 'INVALID_TIMESTAMP',
            ], Response::HTTP_BAD_REQUEST));

            return;
        }

        // Verify signature
        $isValid = $this->verificationService->verify(
            signature: $signature,
            timestamp: (int) $timestamp,
            method: $request->getMethod(),
            path: $path,
            body: $request->getContent()
        );

        if (!$isValid) {
            $event->setResponse(new JsonResponse([
                'success' => false,
                'error' => 'Invalid or expired signature',
                'code' => 'SIGNATURE_INVALID',
            ], Response::HTTP_UNAUTHORIZED));
        }
    }
}
