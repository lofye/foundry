<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use PHPUnit\Framework\TestCase;

final class CLIContextAlignmentNoiseReductionTest extends TestCase
{
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = getcwd() ?: '.';
        chdir(dirname(__DIR__, 2));
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
    }

    public function test_context_persistence_alignment_output_is_self_hosting_and_deterministic(): void
    {
        $first = $this->runCommand(['foundry', 'context', 'check-alignment', '--feature=context-persistence', '--json']);
        $second = $this->runCommand(['foundry', 'context', 'check-alignment', '--feature=context-persistence', '--json']);

        $this->assertSame($first, $second);
        $this->assertSame(0, $first['status']);
        $this->assertSame('ok', $first['payload']['status']);
        $this->assertTrue($first['payload']['can_proceed']);
        $this->assertFalse($first['payload']['requires_repair']);
        $this->assertSame([], $first['payload']['issues']);
        $this->assertSame([], $first['payload']['required_actions']);
    }

    public function test_context_persistence_verification_passes_deterministically(): void
    {
        $first = $this->runCommand(['foundry', 'verify', 'context', '--feature=context-persistence', '--json']);
        $second = $this->runCommand(['foundry', 'verify', 'context', '--feature=context-persistence', '--json']);

        $this->assertSame($first, $second);
        $this->assertSame(0, $first['status']);
        $this->assertSame('pass', $first['payload']['status']);
        $this->assertTrue($first['payload']['can_proceed']);
        $this->assertFalse($first['payload']['requires_repair']);
        $this->assertTrue($first['payload']['consumable']);
        $this->assertSame('ok', $first['payload']['doctor_status']);
        $this->assertSame('ok', $first['payload']['alignment_status']);
        $this->assertSame([], $first['payload']['issues']);
        $this->assertSame([], $first['payload']['required_actions']);
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(array $argv): array
    {
        ob_start();
        $status = (new Application())->run($argv);
        $output = ob_get_clean() ?: '';

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }
}
