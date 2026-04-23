<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Git\GitRepositoryInspector;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class GitRepositoryInspectorTest extends TestCase
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

    public function test_inspect_returns_unavailable_outside_git_repository(): void
    {
        $state = (new GitRepositoryInspector($this->project->root))->inspect();

        $this->assertFalse($state['available']);
        $this->assertFalse($state['dirty']);
        $this->assertSame([], $state['changed_files']);
    }

    public function test_inspect_reports_git_state_and_ignores_internal_artifacts_for_safety_checks(): void
    {
        $this->initGitRepository();
        file_put_contents($this->project->root . '/composer.json', str_replace('"project"', '"project-test"', (string) file_get_contents($this->project->root . '/composer.json')));
        file_put_contents($this->project->root . '/notes.txt', "notes\n");
        mkdir($this->project->root . '/.foundry/snapshots', 0777, true);
        file_put_contents($this->project->root . '/.foundry/snapshots/pre-generate.json', "{}\n");
        mkdir($this->project->root . '/.foundry/plans', 0777, true);
        file_put_contents($this->project->root . '/.foundry/plans/20260423T010203Z_test-plan.json', "{}\n");

        $inspector = new GitRepositoryInspector($this->project->root);
        $state = $inspector->inspect();
        $files = $inspector->describePaths([
            'composer.json',
            'notes.txt',
            '.foundry/snapshots/pre-generate.json',
            '.foundry/plans/20260423T010203Z_test-plan.json',
        ], $state);

        $this->assertTrue($state['available']);
        $this->assertSame('main', $state['branch']);
        $this->assertNotEmpty($state['head']);
        $this->assertTrue($state['dirty']);
        $this->assertContains('composer.json', $state['changed_files']);
        $this->assertContains('notes.txt', $state['changed_files']);
        $this->assertContains('.foundry/snapshots/pre-generate.json', $state['ignored_internal_files']);
        $this->assertContains('.foundry/plans/20260423T010203Z_test-plan.json', $state['ignored_internal_files']);
        $this->assertContains('composer.json', $state['safety_relevant']['changed_files']);
        $this->assertContains('notes.txt', $state['safety_relevant']['changed_files']);
        $this->assertNotContains('.foundry/snapshots/pre-generate.json', $state['safety_relevant']['changed_files']);
        $this->assertNotContains('.foundry/plans/20260423T010203Z_test-plan.json', $state['safety_relevant']['changed_files']);

        $composer = $this->fileRow($files, 'composer.json');
        $notes = $this->fileRow($files, 'notes.txt');
        $snapshot = $this->fileRow($files, '.foundry/snapshots/pre-generate.json');
        $plan = $this->fileRow($files, '.foundry/plans/20260423T010203Z_test-plan.json');

        $this->assertSame('unstaged', $composer['status']);
        $this->assertNotEmpty($composer['last_commit']);
        $this->assertSame('untracked', $notes['status']);
        $this->assertNull($notes['last_commit']);
        $this->assertSame('untracked', $snapshot['status']);
        $this->assertSame('untracked', $plan['status']);
    }

    public function test_commit_only_records_requested_files(): void
    {
        $this->initGitRepository();
        file_put_contents($this->project->root . '/composer.json', str_replace('"project"', '"project-test"', (string) file_get_contents($this->project->root . '/composer.json')));
        file_put_contents($this->project->root . '/notes.txt', "notes\n");

        $inspector = new GitRepositoryInspector($this->project->root);
        $result = $inspector->commit(['notes.txt'], 'Add notes');
        $state = $inspector->inspect();

        $this->assertTrue($result['created']);
        $this->assertSame(['notes.txt'], $result['files']);
        $this->assertNotEmpty($result['commit']);
        $this->assertContains('composer.json', $state['changed_files']);
        $this->assertNotContains('notes.txt', $state['changed_files']);
    }

    private function initGitRepository(): void
    {
        $this->git(['init']);
        $this->git(['branch', '-m', 'main']);
        $this->git(['config', 'user.name', 'Foundry Tests']);
        $this->git(['config', 'user.email', 'foundry-tests@example.invalid']);
        $this->git(['add', '.']);
        $this->git(['commit', '-m', 'Initial commit']);
    }

    /**
     * @param array<int,string> $args
     */
    private function git(array $args): string
    {
        $command = array_merge(['git', '-C', $this->project->root], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(0, $status, trim($stderr) !== '' ? trim($stderr) : trim($stdout));

        return trim($stdout);
    }

    /**
     * @param array<int,array<string,mixed>> $files
     * @return array<string,mixed>
     */
    private function fileRow(array $files, string $path): array
    {
        foreach ($files as $row) {
            if ((string) ($row['path'] ?? '') === $path) {
                return $row;
            }
        }

        self::fail('Missing file row for ' . $path);
    }
}
