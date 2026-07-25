<?php

declare(strict_types=1);

namespace LinkShieldAI\Tests;

use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    public function testHelpPrintsUsage(): void
    {
        $result = $this->runCli('--help');

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('linkshieldai', $result['stdout']);
    }

    public function testMissingApiKeyReturnsJsonError(): void
    {
        $result = $this->runCli('scan https://example.com');

        self::assertSame(1, $result['code']);
        self::assertSame(
            ['error' => 'LinkShieldAI API key is required. Pass apiKey or set LINKSHIELDAI_API_KEY.'],
            json_decode($result['stderr'], true)
        );
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runCli(string $args): array
    {
        $command = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../bin/linkshieldai') . ' ' . $args;
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, __DIR__ . '/..', ['LINKSHIELDAI_API_KEY' => '']);
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [
            'code' => $code,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
        ];
    }
}
