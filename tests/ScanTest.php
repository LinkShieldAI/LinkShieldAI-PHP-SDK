<?php

declare(strict_types=1);

namespace LinkShieldAI\Tests;

use LinkShieldAI\Client;
use LinkShieldAI\HttpResponse;
use PHPUnit\Framework\TestCase;

final class ScanTest extends TestCase
{
    public function testLegacyRequestUsesBearerHeaderAndOnlyUrlQueryParameter(): void
    {
        $transport = static function (string $method, string $url, array $headers): HttpResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://api.test/classify_link?url=https%3A%2F%2Fexample.com', $url);
            self::assertSame('Bearer test-key', $headers['Authorization']);
            self::assertStringNotContainsString('key=', $url);
            return new HttpResponse(200, '{"result":"likely safe"}');
        };
        $result = (new Client('test-key', 'https://api.test', transport: $transport))->detailedCheck('https://example.com');
        self::assertFalse($result->isMalicious);
    }

    public function testScanPostsBearerAuthenticatedV1Request(): void
    {
        $transport = static function (string $method, string $url, array $headers, float $timeout, ?string $body): HttpResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.test/v1/scan', $url);
            self::assertSame('Bearer test-key', $headers['Authorization']);
            self::assertSame(['url' => 'https://example.com', 'mode' => 'standard'], json_decode((string) $body, true));
            return new HttpResponse(200, json_encode([
                'request_id' => 'req-1', 'url' => ['submitted' => 'https://example.com', 'normalized' => 'https://example.com/', 'redirects' => []],
                'mode' => 'standard', 'verdict' => 'SAFE', 'confidence' => null, 'risk_score' => null,
                'threat_categories' => [], 'reason_codes' => ['KNOWN_SAFE_MATCH'], 'brand_target' => null,
                'screenshot_url' => null, 'scanned_at' => '2026-06-21T00:00:00.000Z', 'freshness' => 'live',
                'engine_version' => 'linkshield-v1',
            ], JSON_THROW_ON_ERROR));
        };

        $result = (new Client('test-key', 'https://api.test', transport: $transport))->scan('https://example.com');
        self::assertSame('SAFE', $result['verdict']);
        self::assertSame('req-1', $result['request_id']);
    }

    public function testScreenshotUsesBearerAuthentication(): void
    {
        $transport = static function (string $method, string $url, array $headers): HttpResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://api.test/screenshot/shot.png', $url);
            self::assertSame('Bearer test-key', $headers['Authorization']);
            return new HttpResponse(200, 'PNGDATA');
        };

        $content = (new Client('test-key', 'https://api.test', transport: $transport))->getScreenshot('shot.png');
        self::assertSame('PNGDATA', $content);
    }
}
