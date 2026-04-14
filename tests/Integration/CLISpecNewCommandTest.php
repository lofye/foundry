<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLISpecNewCommandTest extends TestCase
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

    public function test_spec_new_creates_expected_draft_file_and_payload(): void
    {
        $this->writeSpec('execution-spec-system', '001-hierarchical-spec-ids-with-padded-segments');

        $result = $this->runCommand(['foundry', 'spec:new', 'execution-spec-system', 'add-cli-command', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['payload']['success']);
        $this->assertSame('002', $result['payload']['id']);
        $this->assertSame('add-cli-command', $result['payload']['slug']);
        $this->assertSame(
            'docs/specs/execution-spec-system/drafts/002-add-cli-command.md',
            $result['payload']['path'],
        );
        $this->assertFileExists($this->project->root . '/docs/specs/execution-spec-system/drafts/002-add-cli-command.md');
    }

    public function test_spec_new_success_output_matches_required_structure(): void
    {
        $this->writeSpec('execution-spec-system', '001-hierarchical-spec-ids-with-padded-segments');

        $result = $this->runRawCommand(['foundry', 'spec:new', 'execution-spec-system', 'add-cli-command']);

        $this->assertSame(0, $result['status']);
        $this->assertSame(<<<'TEXT'
Created draft spec

Feature: execution-spec-system
ID: 002
Slug: add-cli-command
Path: docs/specs/execution-spec-system/drafts/002-add-cli-command.md

Next steps:
- Fill in the spec sections
- Keep the filename unchanged
- Promote by moving it out of drafts when ready
TEXT . "\n", $result['output']);
    }

    public function test_spec_new_invalid_feature_output_matches_required_structure(): void
    {
        $result = $this->runRawCommand(['foundry', 'spec:new', 'Execution Spec System', 'add-cli-command']);

        $this->assertSame(1, $result['status']);
        $this->assertSame(<<<'TEXT'
Could not create draft spec

Reason: invalid feature name
Feature: Execution Spec System

Required action:
- Use lowercase kebab-case
- Example: execution-spec-system
TEXT . "\n", $result['output']);
    }

    public function test_spec_new_invalid_slug_output_matches_required_structure(): void
    {
        $result = $this->runRawCommand(['foundry', 'spec:new', 'execution-spec-system', 'draft']);

        $this->assertSame(1, $result['status']);
        $this->assertSame(<<<'TEXT'
Could not create draft spec

Reason: invalid slug
Slug: draft

Required action:
- Provide a meaningful kebab-case slug
- Example: add-cli-command
TEXT . "\n", $result['output']);
    }

    public function test_spec_new_existing_target_collision_fails_without_overwriting(): void
    {
        $path = $this->project->root . '/docs/specs/execution-spec-system/drafts/001-add-cli-command.md';
        mkdir($path, 0777, true);

        $result = $this->runRawCommand(['foundry', 'spec:new', 'execution-spec-system', 'add-cli-command']);

        $this->assertSame(1, $result['status']);
        $this->assertSame(<<<'TEXT'
Could not create draft spec

Reason: target file already exists
Path: docs/specs/execution-spec-system/drafts/001-add-cli-command.md

Required action:
- Choose a different slug
- Or inspect existing specs in this feature
TEXT . "\n", $result['output']);
    }

    public function test_spec_new_allocation_failure_output_matches_required_structure(): void
    {
        $directory = $this->project->root . '/docs/specs/execution-spec-system/drafts';
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/not-a-spec.md', "# Execution Spec: not-a-spec\n");

        $result = $this->runRawCommand(['foundry', 'spec:new', 'execution-spec-system', 'add-cli-command']);

        $this->assertSame(1, $result['status']);
        $this->assertSame(<<<'TEXT'
Could not create draft spec

Reason: could not allocate next spec ID
Feature: execution-spec-system

Required action:
- Run `foundry spec:validate`
- Resolve duplicate or invalid spec state in this feature
TEXT . "\n", $result['output']);
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

    /**
     * @param array<int,string> $argv
     * @return array{status:int,output:string}
     */
    private function runRawCommand(array $argv): array
    {
        ob_start();
        $status = (new Application())->run($argv);
        $output = ob_get_clean() ?: '';

        return ['status' => $status, 'output' => $output];
    }

    private function writeSpec(string $feature, string $name, string $subdirectory = ''): void
    {
        $directory = $this->project->root . '/docs/specs/' . $feature . ($subdirectory !== '' ? '/' . $subdirectory : '');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/' . $name . '.md', '# Execution Spec: ' . $name . "\n");
    }
}
