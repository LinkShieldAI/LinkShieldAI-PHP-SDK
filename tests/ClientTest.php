<?php

declare(strict_types=1);

namespace LinkShieldAI\Tests;

use LinkShieldAI\Client;
use LinkShieldAI\Exception\ApiConnectionException;
use LinkShieldAI\Exception\ApiResponseException;
use LinkShieldAI\Exception\ApiStatusException;
use LinkShieldAI\Exception\AuthenticationException;
use LinkShieldAI\Exception\RateLimitException;
use LinkShieldAI\HttpResponse;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function scanPayload(string $verdict = 'SAFE'): array
    {
        return [
            'request_id' => '7f4d',
            'url' => ['submitted' => 'https://example.com', 'normalized' => 'https://example.com/', 'redirects' => []],
            'mode' => 'standard',
            'verdict' => $verdict,
            'confidence' => null,
            'risk_score' => null,
            'threat_categories' => [],
            'reason_codes' => ['KNOWN_SAFE_MATCH'],
            'brand_target' => null,
            'screenshot_url' => null,
            'scanned_at' => '2026-06-21T12:00:00.000Z',
            'freshness' => 'live',
            'engine_version' => 'linkshield-v1',
        ];
    }

    protected function tearDown(): void
    {
        putenv('LINKSHIELDAI_API_KEY');
    }

    public function testRequiresApiKey(): void
    {
        putenv('LINKSHIELDAI_API_KEY');

        $this->expectException(AuthenticationException::class);
        (new Client(transport: fn () => new HttpResponse(200, '{}')))->scan('https://example.com');
    }

    public function testUsesApiKeyFromEnvironment(): void
    {
        putenv('LINKSHIELDAI_API_KEY=env-key');
        $seenHeaders = [];
        $client = $this->client(function (string $method, string $url, array $headers) use (&$seenHeaders): HttpResponse {
            $seenHeaders = $headers;
            return $this->json(self::scanPayload());
        }, apiKey: null);

        $client->scan('https://example.com');

        self::assertSame('Bearer env-key', $seenHeaders['Authorization']);
    }

    public function testScanPostsToV1WithBearerAuth(): void
    {
        $seen = [];
        $client = $this->client(function (string $method, string $url, array $headers, float $timeout, ?string $body) use (&$seen): HttpResponse {
            $seen = compact('method', 'url', 'headers', 'body');
            return $this->json(self::scanPayload());
        });

        $result = $client->scan('https://example.com');

        self::assertSame('POST', $seen['method']);
        self::assertSame('https://api.test/v1/scan', $seen['url']);
        self::assertSame('Bearer test-key', $seen['headers']['Authorization']);
        // The key must never travel in the query string.
        self::assertStringNotContainsString('key=', $seen['url']);
        self::assertSame(['url' => 'https://example.com', 'mode' => 'standard'], json_decode((string) $seen['body'], true));
        self::assertSame('SAFE', $result->verdict);
    }

    public function testScanParsesFullPayload(): void
    {
        $client = $this->client(fn () => $this->json(self::scanPayload()));
        $result = $client->scan('https://example.com');

        self::assertSame('7f4d', $result->requestId);
        self::assertSame(['KNOWN_SAFE_MATCH'], $result->reasonCodes);
        self::assertSame('https://example.com', $result->submittedUrl);
        self::assertSame('https://example.com/', $result->normalizedUrl);
        self::assertSame('linkshield-v1', $result->engineVersion);
    }

    /**
     * @dataProvider verdictProvider
     */
    public function testVerdictHelpers(string $verdict, bool $malicious, bool $safe, bool $unknown): void
    {
        $client = $this->client(fn () => $this->json(self::scanPayload($verdict)));
        $result = $client->scan('https://example.com');

        self::assertSame($malicious, $result->isMalicious());
        self::assertSame($safe, $result->isSafe());
        self::assertSame($unknown, $result->isUnknown());
    }

    /**
     * @return array<string, array{string, bool, bool, bool}>
     */
    public static function verdictProvider(): array
    {
        return [
            'malicious' => ['MALICIOUS', true, false, false],
            'safe' => ['SAFE', false, true, false],
            // UNKNOWN means no decisive signal, which is not the same as clean.
            'unknown' => ['UNKNOWN', false, false, true],
        ];
    }

    public function testScanSendsEachMode(): void
    {
        foreach (['standard', 'detailed', 'deep'] as $mode) {
            $seenBody = null;
            $client = $this->client(function (string $method, string $url, array $headers, float $timeout, ?string $body) use (&$seenBody): HttpResponse {
                $seenBody = $body;
                return $this->json(self::scanPayload());
            });

            $client->scan('https://example.com', $mode);

            self::assertSame($mode, json_decode((string) $seenBody, true)['mode']);
        }
    }

    public function testScanRejectsUnknownMode(): void
    {
        $client = $this->client(fn () => $this->json(self::scanPayload()));

        $this->expectException(\InvalidArgumentException::class);
        $client->scan('https://example.com', 'turbo');
    }

    public function testAiFlagOmittedUnlessRequested(): void
    {
        $seenBody = null;
        $client = $this->client(function (string $method, string $url, array $headers, float $timeout, ?string $body) use (&$seenBody): HttpResponse {
            $seenBody = $body;
            return $this->json(self::scanPayload());
        });

        $client->scan('https://example.com', 'deep');
        self::assertArrayNotHasKey('ai', json_decode((string) $seenBody, true));

        $client->scan('https://example.com', 'deep', ai: true);
        self::assertTrue(json_decode((string) $seenBody, true)['ai']);
    }

    public function testIncludeSignalsParsesSignalsBlock(): void
    {
        $seenBody = null;
        $payload = self::scanPayload('MALICIOUS');
        $payload['signals'] = [
            'url_reputation' => 'safe',
            'domain_reputation' => 'unknown',
            'threat_feed' => 'malicious',
            'external_reputation' => false,
            'allowlist' => ['fandom' => false, 'carrd' => true],
            'degraded' => false,
        ];

        $client = $this->client(function (string $method, string $url, array $headers, float $timeout, ?string $body) use (&$seenBody, $payload): HttpResponse {
            $seenBody = $body;
            return $this->json($payload);
        });

        $result = $client->scan('https://example.com', includeSignals: true);

        self::assertTrue(json_decode((string) $seenBody, true)['include_signals']);
        self::assertNotNull($result->signals);
        self::assertSame('safe', $result->signals->urlReputation);
        self::assertSame('malicious', $result->signals->threatFeed);
        self::assertTrue($result->signals->carrd);
    }

    public function testSignalsAbsentByDefault(): void
    {
        $client = $this->client(fn () => $this->json(self::scanPayload()));
        self::assertNull($client->scan('https://example.com')->signals);
    }

    public function testIsMaliciousUsesScan(): void
    {
        $seenUrl = null;
        $client = $this->client(function (string $method, string $url) use (&$seenUrl): HttpResponse {
            $seenUrl = $url;
            return $this->json(self::scanPayload('MALICIOUS'));
        });

        self::assertTrue($client->isMalicious('https://bad.test'));
        self::assertSame('https://api.test/v1/scan', $seenUrl);
    }

    public function testNsfwCheckSendsBearerAndOnlyUrl(): void
    {
        $seen = [];
        $client = $this->client(function (string $method, string $url, array $headers) use (&$seen): HttpResponse {
            $seen = compact('url', 'headers');
            return $this->json(['result' => 'True']);
        });

        $result = $client->nsfwCheck('https://adult.test');

        self::assertTrue($result->isNsfw);
        self::assertSame('Bearer test-key', $seen['headers']['Authorization']);
        self::assertStringNotContainsString('key=', $seen['url']);
        self::assertStringContainsString('url=', $seen['url']);
    }

    public function testChimeraParsesResponse(): void
    {
        $client = $this->client(fn () => $this->json([
            'detection_method' => 'ai-model',
            'matched_signatures' => 0,
            'probability' => 0.9947241392537983,
            'result' => 'malicious',
            'url' => 'https://steamcomun1ty.com/id=5944478616',
        ]));

        $result = $client->chimera('https://steamcomun1ty.com/id=5944478616');

        self::assertTrue($result->isMalicious);
        self::assertEqualsWithDelta(0.9947241392537983, $result->probability, 0.000001);
        self::assertSame('ai-model', $result->detectionMethod);
    }

    public function testErrorPayloadRaisesResponseException(): void
    {
        $client = $this->client(fn () => $this->json(['Error' => 'Unable to reach the site']));

        $this->expectException(ApiResponseException::class);
        $client->scan('https://down.test');
    }

    public function testRateLimitRaisesRateLimitException(): void
    {
        $client = $this->client(fn () => $this->json(['error' => 'Too many requests'], 429), maxRetries: 0);

        $this->expectException(RateLimitException::class);
        $client->scan('https://example.com');
    }

    public function testHttpErrorRaisesStatusException(): void
    {
        $client = $this->client(fn () => $this->json(['error' => 'boom'], 500), maxRetries: 0);

        $this->expectException(ApiStatusException::class);
        $client->chimera('https://bad.test');
    }

    public function testRetriesTransientStatusThenSucceeds(): void
    {
        $calls = 0;
        $client = $this->client(function () use (&$calls): HttpResponse {
            $calls++;
            if ($calls === 1) {
                return $this->json(['error' => 'temporary'], 503);
            }
            return $this->json(self::scanPayload());
        });

        $result = $client->scan('https://example.com');

        self::assertTrue($result->isSafe());
        self::assertSame(2, $calls);
    }

    public function testConnectionErrorAfterRetries(): void
    {
        $calls = 0;
        $client = $this->client(function () use (&$calls): HttpResponse {
            $calls++;
            throw new \RuntimeException('boom');
        });

        try {
            $client->scan('https://example.com');
            self::fail('Expected ApiConnectionException.');
        } catch (ApiConnectionException) {
            self::assertSame(3, $calls);
        }
    }

    private function client(callable $transport, ?string $apiKey = 'test-key', int $maxRetries = 2): Client
    {
        return new Client(
            apiKey: $apiKey,
            baseUrl: 'https://api.test',
            maxRetries: $maxRetries,
            backoffFactor: 0.0,
            transport: $transport,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $statusCode = 200): HttpResponse
    {
        return new HttpResponse($statusCode, json_encode($payload, JSON_THROW_ON_ERROR), ['content-type' => 'application/json']);
    }
}
