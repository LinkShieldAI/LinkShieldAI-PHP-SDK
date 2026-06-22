<?php

declare(strict_types=1);

namespace LinkShieldAI\Result;

final class DetailedCheckResult
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly ?string $result,
        public readonly ?string $screenshotUrl,
        public readonly ?string $tag,
        public readonly bool $isMalicious,
        public readonly array $raw,
    ) {
    }
}
