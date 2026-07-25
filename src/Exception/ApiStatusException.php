<?php

declare(strict_types=1);

namespace LinkShieldAI\Exception;

class ApiStatusException extends LinkShieldAIException
{
    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?array $payload = null,
    ) {
        parent::__construct($message);
    }
}
