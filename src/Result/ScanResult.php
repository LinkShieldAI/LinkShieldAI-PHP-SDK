<?php

declare(strict_types=1);

namespace LinkShieldAI\Result;

/**
 * A POST /v1/scan result.
 */
final class ScanResult
{
    /**
     * @param list<string>         $threatCategories
     * @param list<string>         $reasonCodes
     * @param list<string>         $redirects
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $verdict = 'UNKNOWN',
        public readonly ?string $requestId = null,
        public readonly ?string $mode = null,
        public readonly ?float $confidence = null,
        public readonly ?float $riskScore = null,
        public readonly array $threatCategories = [],
        public readonly array $reasonCodes = [],
        public readonly ?string $brandTarget = null,
        public readonly ?string $screenshotUrl = null,
        public readonly ?string $submittedUrl = null,
        public readonly ?string $normalizedUrl = null,
        public readonly array $redirects = [],
        public readonly ?string $scannedAt = null,
        public readonly ?string $freshness = null,
        public readonly ?string $engineVersion = null,
        public readonly ?ScanSignals $signals = null,
        public readonly array $raw = [],
    ) {
    }

    /** True only for an explicit MALICIOUS verdict. */
    public function isMalicious(): bool
    {
        return $this->verdict === 'MALICIOUS';
    }

    /**
     * True only for an explicit SAFE verdict.
     *
     * UNKNOWN is deliberately not safe: it means no decisive signal was
     * available, which is not the same as a clean result.
     */
    public function isSafe(): bool
    {
        return $this->verdict === 'SAFE';
    }

    public function isUnknown(): bool
    {
        return $this->verdict !== 'MALICIOUS' && $this->verdict !== 'SAFE';
    }
}
