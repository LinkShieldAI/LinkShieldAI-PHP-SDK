<?php

declare(strict_types=1);

namespace LinkShieldAI\Exception;

class ApiResponseException extends LinkShieldAIException
{
    public function __construct(
        string $message,
        public readonly mixed $payload = null,
    ) {
        parent::__construct($message);
    }
}
