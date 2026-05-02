<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLISpecPlanCommandTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_spec_plan_creates_plan_with_required_heading_and_sections(): void
    {
        $this->writeActiveSpec('execution-spec-system', '008-implementation-plan-files');

        $result = $this->runCommand(['foundry', 'spec:plan', 'execution-spec-system', '008', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('created', $result['payload']['status']);
        $this->assertSame(
            'docs/features/execution-spec-system/specs/008-implementation-plan-files.md',
            $result['payload']['spec'],
        );
        $this->assertSame(
            'docs/features/execution-spec-system/plans/008-implementation-plan-files.md',
            $result['payload']['plan'],
        );

        $planPath = $this->project->root . '/docs/features/execution-spec-system/plans/008-implementation-plan-files.md';
        $this->assertFileExists($planPath);
        $contents = (string) file_get_contents($planPath);
        $this->assertStringStartsWith('# Implementation Plan: 008-implementation-plan-files', $contents);
        $this->assertStringContainsString('## Implementation Steps', $contents);
        $this->assertStringContainsString('php bin/foundry spec:validate --require-plans --json', $contents);
    }

    public function test_spec_plan_refuses_overwrite_without_force(): void
    {
        $this->writeActiveSpec('execution-spec-system', '008-implementation-plan-files');
        $path = $this->project->root . '/docs/features/execution-spec-system/plans/008-implementation-plan-files.md';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, "# Implementation Plan: 008-implementation-plan-files\n");

        $result = $this->runCommand(['foundry', 'spec:plan', 'execution-spec-system', '008', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('error', $result['payload']['status']);
        $this->assertSame('plan_already_exists', $result['payload']['error']);
        $this->assertSame('docs/features/execution-spec-system/plans/008-implementation-plan-files.md', $result['payload']['plan']);
    }

    public function test_spec_plan_failures_are_deterministic_for_missing_feature_and_spec(): void
    {
        $missingFeature = $this->runCommand(['foundry', 'spec:plan', 'missing-feature', '008', '--json']);
        $this->assertSame(1, $missingFeature['status']);
        $this->assertSame('feature_not_found', $missingFeature['payload']['error']);

        $this->writeActiveSpec('execution-spec-system', '008-implementation-plan-files');
        $missingSpec = $this->runCommand(['foundry', 'spec:plan', 'execution-spec-system', '009', '--json']);
        $this->assertSame(1, $missingSpec['status']);
        $this->assertSame('spec_not_found', $missingSpec['payload']['error']);
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

    private function writeActiveSpec(string $feature, string $name): void
    {
        $this->writeRawFile(
            'docs/features/' . $feature . '/specs/' . $name . '.md',
            <<<MD
# Execution Spec: {$name}

## Feature

- {$feature}
MD
            . "\n",
        );
    }

    private function writeRawFile(string $relativePath, string $contents): void
    {
        $absolutePath = $this->project->root . '/' . $relativePath;
        $directory = dirname($absolutePath);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($absolutePath, $contents);
    }
}
