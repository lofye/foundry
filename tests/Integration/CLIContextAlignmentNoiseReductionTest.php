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

    public function test_context_persistence_alignment_output_is_concise_and_deterministic(): void
    {
        $first = $this->runCommand(['foundry', 'context', 'check-alignment', '--feature=context-persistence', '--json']);
        $second = $this->runCommand(['foundry', 'context', 'check-alignment', '--feature=context-persistence', '--json']);

        $codes = array_values(array_map(
            static fn(array $issue): string => (string) ($issue['code'] ?? ''),
            $first['payload']['issues'],
        ));

        $this->assertSame($first, $second);
        $this->assertSame(1, $first['status']);
        $this->assertSame('mismatch', $first['payload']['status']);
        $this->assertCount(3, $first['payload']['issues']);
        $this->assertSame([
            'untracked_spec_requirement',
            'untracked_spec_requirement',
            'untracked_spec_requirement',
        ], $codes);
        $this->assertSame([
            'Reflect the spec requirement in Current State, Open Questions, or Next Steps.',
        ], $first['payload']['required_actions']);
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
