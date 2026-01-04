<?php

declare(strict_types=1);

namespace WlMonitoring\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use SodiumException;

class SignatureVerificationService
{
    private const CONFIG_PREFIX = 'WlMonitoring.config.';

    /**
     * Maximum allowed signature age in seconds (5 minutes).
     * This is hardcoded to prevent misconfiguration.
     */
    private const MAX_SIGNATURE_AGE = 300;

    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    /**
     * Check if a public key is configured.
     */
    public function hasPublicKey(): bool
    {
        return !empty($this->getPublicKey());
    }

    /**
     * Get the maximum allowed signature age in seconds.
     */
    public function getMaxAge(): int
    {
        return self::MAX_SIGNATURE_AGE;
    }

    /**
     * Get the configured public key.
     */
    public function getPublicKey(): ?string
    {
        $publicKey = $this->systemConfigService->get(self::CONFIG_PREFIX . 'monitoringPublicKey');

        return $publicKey ? trim((string) $publicKey) : null;
    }

    /**
     * Verify a request signature.
     */
    public function verify(
        string $signature,
        int $timestamp,
        string $method,
        string $path,
        string $body = ''
    ): bool {
        $publicKeyBase64 = $this->getPublicKey();

        if (empty($publicKeyBase64)) {
            return false;
        }

        // Check timestamp freshness
        $now = time();
        $maxAge = $this->getMaxAge();

        if (abs($now - $timestamp) > $maxAge) {
            return false; // Request too old or from the future
        }

        // Reconstruct the signed message
        $bodyHash = hash('sha256', $body);
        $message = "{$timestamp}:{$method}:{$path}:{$bodyHash}";

        try {
            $publicKey = sodium_base642bin($publicKeyBase64, SODIUM_BASE64_VARIANT_ORIGINAL);
            $signatureBytes = sodium_base642bin($signature, SODIUM_BASE64_VARIANT_ORIGINAL);

            return sodium_crypto_sign_verify_detached($signatureBytes, $message, $publicKey);
        } catch (SodiumException) {
            return false;
        }
    }
}
