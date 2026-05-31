<?php

declare(strict_types=1);

namespace LinkShieldAI\Result;

final class NsfwCheckResult
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly ?string $result,
        public readonly bool $isNsfw,
        public readonly array $raw,
    ) {
    }
}
