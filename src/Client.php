<?php

declare(strict_types=1);

namespace LinkShieldAI;

use LinkShieldAI\Exception\ApiConnectionException;
use LinkShieldAI\Exception\ApiResponseException;
use LinkShieldAI\Exception\ApiStatusException;
use LinkShieldAI\Exception\AuthenticationException;
use LinkShieldAI\Exception\LinkShieldAIException;
use LinkShieldAI\Exception\RateLimitException;
use LinkShieldAI\Result\BasicCheckResult;
use LinkShieldAI\Result\ChimeraResult;
use LinkShieldAI\Result\DetailedCheckResult;
use LinkShieldAI\Result\NsfwCheckResult;

final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.linkshieldai.com';

    /**
     * @var callable(string, string, array<string, string>, float): HttpResponse|null
     */
    private $transport;

    /**
     * @param callable(string, string, array<string, string>, float): HttpResponse|null $transport
     */
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = 10.0,
        private readonly int $maxRetries = 2,
        private readonly float $backoffFactor = 0.5,
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    public function basicCheck(string $url): BasicCheckResult
    {
        $payload = $this->getJson('/', ['url' => $url]);
        return $this->parseBasic($payload);
    }

    public function detailedCheck(string $url): DetailedCheckResult
    {
        $payload = $this->getJson('/classify_link', ['url' => $url]);
        return $this->parseDetailed($payload);
    }

    public function nsfwCheck(string $url): NsfwCheckResult
    {
        $payload = $this->getJson('/nsfw/site', ['url' => $url]);
        return $this->parseNsfw($payload);
    }

    public function chimera(string $url): ChimeraResult
    {
        $payload = $this->getJson('/chimera', ['url' => $url]);
        return $this->parseChimera($payload);
    }

    /** @return array<string, mixed> Stable /v1/scan response. */
    public function scan(string $url, string $mode = 'standard'): array
    {
        if (!in_array($mode, ['standard', 'detailed', 'deep'], true)) {
            throw new \InvalidArgumentException('Scan mode must be standard, detailed, or deep.');
        }
        $body = json_encode(['url' => $url, 'mode' => $mode], JSON_THROW_ON_ERROR);
        $response = $this->request('POST', '/v1/scan', [], [
            'Authorization' => 'Bearer ' . $this->resolvedApiKey(),
            'Content-Type' => 'application/json',
        ], $body);
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new ApiResponseException('LinkShieldAI API returned a non-object JSON response.', $payload);
        }
        return $payload;
    }

    public function isMalicious(string $url): bool
    {
        return $this->basicCheck($url)->isMalicious;
    }

    public function isNsfw(string $url): bool
    {
        return $this->nsfwCheck($url)->isNsfw;
    }

    public function getScreenshot(string $fileNameOrUrl, ?string $outputPath = null): string
    {
        $fileName = $this->screenshotFileName($fileNameOrUrl);
        $response = $this->request('GET', '/screenshot/' . rawurlencode($fileName), [], [
            'Authorization' => 'Bearer ' . $this->resolvedApiKey(),
        ]);
        $content = $response->body;

        if ($outputPath !== null) {
            $written = @file_put_contents($outputPath, $content);
            if ($written === false) {
                throw new ApiResponseException("Unable to write screenshot to {$outputPath}.");
            }
        }

        return $content;
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function getJson(string $path, array $params): array
    {
        $response = $this->request('GET', $path, $params, ['Authorization' => 'Bearer ' . $this->resolvedApiKey()]);
        $payload = json_decode($response->body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $contentType = $response->header('content-type') ?: 'unknown';
            $preview = substr(str_replace("\n", ' ', trim($response->body)), 0, 300);
            $message = 'LinkShieldAI API returned invalid JSON.';
            if ($preview !== '') {
                $message .= " Content-Type: {$contentType}. Body preview: {$preview}";
            }
            throw new ApiResponseException($message);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new ApiResponseException('LinkShieldAI API returned a non-object JSON response.', $payload);
        }

        if (!empty($payload['Error']) || !empty($payload['error'])) {
            throw new ApiResponseException($this->errorMessageFromPayload($payload), $payload);
        }

        return $payload;
    }

    /**
     * @param array<string, string> $params
     */
    private function request(string $method, string $path, array $params = [], array $headers = [], ?string $body = null): HttpResponse
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($params !== []) {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        $maxRetries = max(0, $this->maxRetries);
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->send($method, $url, $headers, $body);
            } catch (LinkShieldAIException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                if ($attempt < $maxRetries) {
                    $this->delay($attempt);
                    continue;
                }
                throw new ApiConnectionException('Could not connect to LinkShieldAI API: ' . $exception->getMessage(), 0, $exception);
            }

            if ($attempt < $maxRetries && in_array($response->statusCode, [429, 502, 503, 504], true)) {
                $this->delay($attempt, $response);
                continue;
            }

            $this->handleStatusError($response);
            return $response;
        }

        throw new ApiConnectionException('Could not connect to LinkShieldAI API after retries.');
    }

    private function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $this->timeout, $body);
        }

        if (function_exists('curl_init')) {
            return $this->sendWithCurl($method, $url, $headers, $body);
        }

        return $this->sendWithStream($method, $url, $headers, $body);
    }

    private function sendWithCurl(string $method, string $url, array $requestHeaders = [], ?string $requestBody = null): HttpResponse
    {
        $headers = [];
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ApiConnectionException('Unable to initialize cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$headers): int {
                $length = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $headers[trim($parts[0])] = trim($parts[1]);
                }
                return $length;
            },
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => array_map(static fn(string $name, string $value): string => $name . ': ' . $value, array_keys($requestHeaders), $requestHeaders),
            CURLOPT_POSTFIELDS => $requestBody,
        ]);

        $body = curl_exec($handle);
        if ($body === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new ApiConnectionException('Could not connect to LinkShieldAI API: ' . $error);
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($statusCode, (string) $body, $headers);
    }

    private function sendWithStream(string $method, string $url, array $requestHeaders = [], ?string $requestBody = null): HttpResponse
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", array_map(static fn(string $name, string $value): string => $name . ': ' . $value, array_keys($requestHeaders), $requestHeaders)),
                'content' => $requestBody ?? '',
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $error = error_get_last()['message'] ?? 'unknown connection error';
            throw new ApiConnectionException('Could not connect to LinkShieldAI API: ' . $error);
        }

        $headers = [];
        $statusCode = 0;
        foreach ($http_response_header ?? [] as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $headerLine, $matches)) {
                $statusCode = (int) $matches[1];
                continue;
            }
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        return new HttpResponse($statusCode, $body, $headers);
    }

    private function handleStatusError(HttpResponse $response): void
    {
        if ($response->statusCode < 400) {
            return;
        }

        $payload = json_decode($response->body, true);
        $payload = is_array($payload) && !array_is_list($payload) ? $payload : null;

        if ($response->statusCode === 429) {
            throw new RateLimitException(retryAfter: $this->parseRetryAfter($response->header('retry-after')));
        }

        $message = $this->errorMessageFromPayload($payload, "LinkShieldAI API returned HTTP {$response->statusCode}.");
        throw new ApiStatusException($message, $response->statusCode, $payload);
    }

    private function resolvedApiKey(): string
    {
        $resolved = $this->apiKey ?: getenv('LINKSHIELDAI_API_KEY');
        if (!$resolved) {
            throw new AuthenticationException('LinkShieldAI API key is required. Pass apiKey or set LINKSHIELDAI_API_KEY.');
        }
        return (string) $resolved;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function errorMessageFromPayload(?array $payload, string $fallback = 'LinkShieldAI API request failed.'): string
    {
        if ($payload !== null) {
            if (isset($payload['error']) && is_array($payload['error']) && isset($payload['error']['message'])) {
                return (string) $payload['error']['message'];
            }
            foreach (['Error', 'error', 'message', 'detail'] as $key) {
                if (!empty($payload[$key]) && is_scalar($payload[$key])) {
                    return (string) $payload[$key];
                }
            }
        }
        return $fallback;
    }

    private function parseRetryAfter(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? max((float) $value, 0.0) : null;
    }

    private function delay(int $attempt, ?HttpResponse $response = null): void
    {
        $retryAfter = $response ? $this->parseRetryAfter($response->header('retry-after')) : null;
        $seconds = $retryAfter ?? max(0.0, $this->backoffFactor) * (2 ** $attempt);
        if ($seconds > 0) {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseBasic(array $payload): BasicCheckResult
    {
        $result = $payload['result'] ?? null;
        return new BasicCheckResult(
            result: $result === null ? null : (string) $result,
            isMalicious: $this->isMaliciousText($result),
            isSafe: $this->isSafeText($result),
            raw: $payload,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseDetailed(array $payload): DetailedCheckResult
    {
        $result = $payload['result'] ?? null;
        $screenshotUrl = $payload['screenshot url'] ?? $payload['screenshot_url'] ?? $payload['screenshotUrl'] ?? null;

        return new DetailedCheckResult(
            result: $result === null ? null : (string) $result,
            screenshotUrl: $screenshotUrl ? (string) $screenshotUrl : null,
            tag: array_key_exists('tag', $payload) && $payload['tag'] !== null ? (string) $payload['tag'] : null,
            isMalicious: $this->isMaliciousText($result),
            raw: $payload,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseNsfw(array $payload): NsfwCheckResult
    {
        $result = $payload['result'] ?? null;
        return new NsfwCheckResult(
            result: $result === null ? null : (string) $result,
            isNsfw: strtolower(trim((string) ($result ?? ''))) === 'true',
            raw: $payload,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseChimera(array $payload): ChimeraResult
    {
        $result = $payload['result'] ?? null;
        $probability = $payload['probability'] ?? null;
        $matchedSignatures = $payload['matched_signatures'] ?? $payload['matchedSignatures'] ?? null;

        return new ChimeraResult(
            result: $result === null ? null : (string) $result,
            probability: is_numeric($probability) ? (float) $probability : null,
            detectionMethod: isset($payload['detection_method']) ? (string) $payload['detection_method'] : (isset($payload['detectionMethod']) ? (string) $payload['detectionMethod'] : null),
            matchedSignatures: is_numeric($matchedSignatures) ? (int) $matchedSignatures : null,
            url: isset($payload['url']) ? (string) $payload['url'] : null,
            isMalicious: $this->isMaliciousText($result),
            raw: $payload,
        );
    }

    private function isMaliciousText(mixed $result): bool
    {
        $value = strtolower(trim((string) ($result ?? '')));
        foreach (["didn't detect", 'did not detect', 'no malicious', 'not malicious'] as $phrase) {
            if (str_contains($value, $phrase)) {
                return false;
            }
        }
        return in_array($value, ['might be malicious', 'malicious', 'true'], true) || str_contains($value, 'malicious');
    }

    private function isSafeText(mixed $result): bool
    {
        $value = strtolower(trim((string) ($result ?? '')));
        return in_array($value, [
            'likely safe',
            "the system didn't detect anything malicious.",
            'false',
            'benign',
        ], true) || str_contains($value, 'safe') || str_contains($value, 'benign');
    }

    private function screenshotFileName(string $fileNameOrUrl): string
    {
        $path = parse_url($fileNameOrUrl, PHP_URL_PATH);
        $candidate = basename($path ?: $fileNameOrUrl);
        if ($candidate === '' || $candidate === '.' || $candidate === DIRECTORY_SEPARATOR) {
            throw new \InvalidArgumentException('A screenshot file name or screenshot URL is required.');
        }
        return $candidate;
    }
}
