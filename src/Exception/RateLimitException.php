<?php

declare(strict_types=1);

namespace LinkShieldAI\Exception;

class RateLimitException extends LinkShieldAIException
{
    public function __construct(
        string $message = 'LinkShieldAI API rate limit exceeded.',
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
