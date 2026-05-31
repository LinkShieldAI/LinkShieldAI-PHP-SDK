<?php

declare(strict_types=1);

namespace LinkShieldAI\Result;

final class BasicCheckResult
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly ?string $result,
        public readonly bool $isMalicious,
        public readonly bool $isSafe,
        public readonly array $raw,
    ) {
    }
}
