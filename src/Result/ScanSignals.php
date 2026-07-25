<?php

declare(strict_types=1);

namespace LinkShieldAI\Result;

/**
 * Raw per-source signals behind a verdict.
 *
 * Only present when scan() was called with includeSignals: true. The
 * reputation values are "malicious", "safe" or "unknown".
 */
final class ScanSignals
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $urlReputation = 'unknown',
        public readonly string $domainReputation = 'unknown',
        public readonly string $threatFeed = 'unknown',
        public readonly bool $externalReputation = false,
        public readonly bool $fandom = false,
        public readonly bool $carrd = false,
        public readonly bool $degraded = false,
        public readonly array $raw = [],
    ) {
    }
}
