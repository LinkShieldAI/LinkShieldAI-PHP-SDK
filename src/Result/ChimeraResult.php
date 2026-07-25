<?php

declare(strict_types=1);

namespace LinkShieldAI\Result;

final class ChimeraResult
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly ?string $result,
        public readonly ?float $probability,
        public readonly ?string $detectionMethod,
        public readonly ?int $matchedSignatures,
        public readonly ?string $url,
        public readonly bool $isMalicious,
        public readonly array $raw,
    ) {
    }
}
