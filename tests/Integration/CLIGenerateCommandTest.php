<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\GenerateCommand;
use Foundry\Generate\GenerationPlan;
use Foundry\Generate\InteractiveGenerateReviewRequest;
use Foundry\Generate\InteractiveGenerateReviewResult;
use Foundry\Generate\InteractiveGenerateReviewer;
use Foundry\Packs\HostedPackRegistry;
use Foundry\Packs\PackChecksum;
use Foundry\Packs\PackManager;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIGenerateCommandTest extends TestCase
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

    public function test_generate_reports_missing_pack_without_auto_install(): void
    {
        $app = new Application();

        $result = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'blog',
            'post',
            'notes',
            '--mode=new',
            '--packs=foundry/blog',
            '--json',
        ]);

        $this->assertSame(1, $result['status']);
        $this->assertSame('GENERATE_PACK_INSTALL_REQUIRED', $result['payload']['error']['code']);
        $this->assertSame(['pack:foundry/blog'], $result['payload']['error']['details']['missing_capabilities']);
        $this->assertSame(['foundry/blog'], $result['payload']['error']['details']['suggested_packs']);
        $this->assertFileExists($this->project->root . '/.foundry/snapshots/pre-generate.json');
        $this->assertFileDoesNotExist($this->project->root . '/.foundry/snapshots/post-generate.json');
        $this->assertFileDoesNotExist($this->project->root . '/.foundry/diffs/last.json');
    }

    public function test_generate_uses_installed_pack_generator_when_pack_is_available(): void
    {
        $app = new Application();

        $install = $this->runCommand($app, ['foundry', 'pack', 'install', $this->fixturePath('foundry-blog'), '--json']);
        $this->assertSame(0, $install['status']);

        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'blog',
            'post',
            'notes',
            '--mode=new',
            '--packs=foundry/blog',
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $this->assertSame('pack', $generate['payload']['plan']['origin']);
        $this->assertSame('generate blog-post', $generate['payload']['plan']['generator_id']);
        $this->assertArrayHasKey('confidence', $generate['payload']['plan']);
        $this->assertArrayHasKey('plan_confidence', $generate['payload']);
        $this->assertArrayHasKey('outcome_confidence', $generate['payload']);
        $this->assertSame(['foundry/blog'], $generate['payload']['packs_used']);
        $this->assertSame('pack:foundry/blog', $generate['payload']['metadata']['target']['resolved']);
        $this->assertFileExists($this->project->root . '/app/features/blog_post_notes/feature.yaml');
    }

    public function test_generate_can_auto_install_required_pack_when_allowed(): void
    {
        $downloadUrl = 'https://downloads.example/foundry-blog-1.0.0.zip';
        $manifest = $this->fixtureManifest('foundry-blog');
        $app = $this->hostedGenerateApplication(
            [[
                'name' => 'foundry/blog',
                'version' => '1.0.0',
                'description' => 'Blog workflow tools',
                'download_url' => $downloadUrl,
                'checksum' => $manifest['checksum'],
                'signature' => $manifest['signature'],
                'verified' => true,
            ]],
            [$downloadUrl => $this->fixtureArchive('foundry-blog')],
        );

        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'blog',
            'post',
            'notes',
            '--mode=new',
            '--packs=foundry/blog',
            '--allow-pack-install',
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $this->assertSame('pack', $generate['payload']['plan']['origin']);
        $this->assertSame('foundry/blog', $generate['payload']['packs_installed'][0]['pack']);
        $this->assertSame('registry', $generate['payload']['packs_installed'][0]['source']['type']);
        $this->assertArrayHasKey('plan_confidence', $generate['payload']);
        $this->assertArrayHasKey('outcome_confidence', $generate['payload']);
        $this->assertFileExists($this->project->root . '/.foundry/packs/foundry/blog/1.0.0/foundry.json');
        $this->assertFileExists($this->project->root . '/app/features/blog_post_notes/feature.yaml');
    }

    public function test_generate_records_architectural_snapshots_diff_and_post_explain(): void
    {
        $app = new Application();

        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--explain',
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $this->assertFileExists($this->project->root . '/.foundry/snapshots/pre-generate.json');
        $this->assertFileExists($this->project->root . '/.foundry/snapshots/post-generate.json');
        $this->assertFileExists($this->project->root . '/.foundry/diffs/last.json');
        $this->assertSame('.foundry/snapshots/pre-generate.json', $generate['payload']['snapshots']['pre']);
        $this->assertSame('.foundry/snapshots/post-generate.json', $generate['payload']['snapshots']['post']);
        $this->assertSame('.foundry/diffs/last.json', $generate['payload']['snapshots']['diff']);
        $this->assertArrayHasKey('plan_confidence', $generate['payload']);
        $this->assertArrayHasKey('outcome_confidence', $generate['payload']);
        $this->assertIsArray($generate['payload']['architecture_diff']);
        $this->assertArrayHasKey('confidence', $generate['payload']['architecture_diff']);
        $this->assertGreaterThan(0, $generate['payload']['architecture_diff']['summary']['added']);
        $this->assertSame(
            'feature:' . $generate['payload']['plan']['metadata']['feature'],
            $generate['payload']['post_explain']['subject']['id'],
        );
        $this->assertArrayHasKey('confidence', $generate['payload']['post_explain']);

        $diff = $this->runCommand($app, ['foundry', 'explain', '--diff', '--json']);
        $this->assertSame(0, $diff['status']);
        $this->assertSame($generate['payload']['architecture_diff'], $diff['payload']);
    }

    public function test_explain_diff_fails_cleanly_when_snapshots_are_incompatible(): void
    {
        mkdir($this->project->root . '/.foundry/snapshots', 0777, true);
        mkdir($this->project->root . '/.foundry/diffs', 0777, true);

        file_put_contents($this->project->root . '/.foundry/snapshots/pre-generate.json', json_encode([
            'schema_version' => 1,
            'label' => 'pre-generate',
            'metadata' => ['explain_schema_version' => 2],
            'categories' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->project->root . '/.foundry/snapshots/post-generate.json', json_encode([
            'schema_version' => 1,
            'label' => 'post-generate',
            'metadata' => ['explain_schema_version' => 99],
            'categories' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->project->root . '/.foundry/diffs/last.json', json_encode([
            'schema_version' => 1,
            'summary' => ['added' => 0, 'removed' => 0, 'modified' => 0],
            'added' => [],
            'removed' => [],
            'modified' => [],
        ], JSON_THROW_ON_ERROR));

        $app = new Application();
        $diff = $this->runCommand($app, ['foundry', 'explain', '--diff', '--json']);

        $this->assertSame(1, $diff['status']);
        $this->assertSame('EXPLAIN_DIFF_SNAPSHOT_INCOMPATIBLE', $diff['payload']['error']['code']);
    }

    public function test_generate_blocks_dirty_git_repo_without_allow_dirty(): void
    {
        $this->initGitRepository();
        file_put_contents($this->project->root . '/composer.json', str_replace('"project"', '"project-test"', (string) file_get_contents($this->project->root . '/composer.json')));

        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--json',
        ]);

        $this->assertSame(1, $generate['status']);
        $this->assertSame('GENERATE_GIT_DIRTY_TREE', $generate['payload']['error']['code']);
        $this->assertContains('composer.json', $generate['payload']['error']['details']['changed_files']);
        $this->assertFileDoesNotExist($this->project->root . '/app/features/comments/feature.yaml');
    }

    public function test_generate_can_allow_dirty_repo_and_persist_generate_history(): void
    {
        $this->initGitRepository();
        file_put_contents($this->project->root . '/composer.json', str_replace('"project"', '"project-test"', (string) file_get_contents($this->project->root . '/composer.json')));

        $app = new Application();
        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
            '--allow-dirty',
            '--json',
        ]);

        $history = $this->runCommand($app, ['foundry', 'history', '--kind=generate', '--json']);

        $this->assertSame(0, $generate['status']);
        $this->assertTrue($generate['payload']['git']['available']);
        $this->assertContains(
            'Git working tree was dirty before generation; auto-commit may be skipped for safety.',
            $generate['payload']['git']['warnings'],
        );
        $this->assertSame('generate', $generate['payload']['record']['kind']);
        $this->assertSame(0, $history['status']);
        $this->assertContains(
            $generate['payload']['record']['id'],
            array_values(array_map(
                static fn(array $entry): string => (string) ($entry['id'] ?? ''),
                $history['payload']['entries'],
            )),
        );
    }

    public function test_generate_git_preflight_ignores_internal_foundry_artifacts_between_runs(): void
    {
        $this->initGitRepository();
        $app = new Application();

        $first = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
            '--json',
        ]);
        $second = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
            '--json',
        ]);

        $this->assertSame(0, $first['status']);
        $this->assertSame(0, $second['status']);
        $this->assertTrue($second['payload']['git']['available']);
        $this->assertSame([], $second['payload']['git']['warnings']);
    }

    public function test_generate_can_create_scoped_git_commit_after_successful_verification(): void
    {
        $this->initGitRepository();
        $app = new Application();

        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--git-commit',
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $this->assertTrue($generate['payload']['git']['commit']['created']);
        $this->assertNotEmpty($generate['payload']['git']['commit']['commit']);
        $this->assertContains('app/features/comments_system/feature.yaml', $generate['payload']['git']['commit']['files']);
        $this->assertSame('foundry generate (new): Create comments', $this->git(['log', '-1', '--format=%s']));
    }

    public function test_generate_interactive_dry_run_includes_review_payload(): void
    {
        $app = $this->interactiveApplication(
            static fn(InteractiveGenerateReviewRequest $request): InteractiveGenerateReviewResult => new InteractiveGenerateReviewResult(
                approved: true,
                plan: $request->plan,
                userDecisions: [['type' => 'approve']],
                preview: ['summary' => [], 'actions' => [], 'diffs' => []],
                risk: ['level' => 'LOW', 'reasons' => ['Plan is additive only.'], 'risky_action_indexes' => [], 'risky_paths' => []],
            ),
        );

        $result = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--interactive',
            '--dry-run',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['payload']['interactive']['enabled']);
        $this->assertTrue($result['payload']['interactive']['approved']);
        $this->assertArrayHasKey('original_plan', $result['payload']['interactive']);
        $this->assertNull($result['payload']['interactive']['modified_plan']);
        $this->assertSame('interactive', $result['payload']['safety_routing']['recommended_mode']);
        $this->assertTrue($result['payload']['safety_routing']['forced_by_user']);
        $this->assertSame(['explicit_interactive'], $result['payload']['safety_routing']['reason_codes']);
    }

    public function test_generate_new_dry_run_exposes_safety_routing_payload(): void
    {
        $result = $this->runCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--dry-run',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertIsArray($result['payload']['safety_routing']);
        $this->assertContains($result['payload']['safety_routing']['recommended_mode'], ['interactive', 'non_interactive']);
        $this->assertSame('generate-with-safety-routing', $result['payload']['safety_routing']['skill']['name']);
        $this->assertArrayHasKey('signals', $result['payload']['safety_routing']);
        $this->assertArrayHasKey('reason_codes', $result['payload']['safety_routing']);
    }

    public function test_generate_modify_dry_run_recommends_interactive_safety_routing(): void
    {
        $baseline = $this->runCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--json',
        ]);
        $this->assertSame(0, $baseline['status']);

        $feature = (string) $baseline['payload']['plan']['metadata']['feature'];

        $result = $this->runCommand(new Application(), [
            'foundry',
            'generate',
            'Refine',
            'comments',
            'notes',
            '--mode=modify',
            '--target=' . $feature,
            '--dry-run',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertSame('interactive', $result['payload']['safety_routing']['recommended_mode']);
        $this->assertTrue($result['payload']['safety_routing']['recommended_interactive']);
        $this->assertSame('MEDIUM', $result['payload']['safety_routing']['signals']['risk_level']);
        $this->assertContains('elevated_risk', $result['payload']['safety_routing']['reason_codes']);
    }

    public function test_generate_interactive_smoke_invocation_reaches_review_and_rejects_non_destructively(): void
    {
        $app = $this->interactiveApplication(
            static fn(InteractiveGenerateReviewRequest $request): InteractiveGenerateReviewResult => new InteractiveGenerateReviewResult(
                approved: false,
                plan: $request->plan,
                userDecisions: [['type' => 'reject']],
                preview: ['summary' => [], 'actions' => [], 'diffs' => []],
                risk: ['level' => 'LOW', 'reasons' => ['Plan is additive only.'], 'risky_action_indexes' => [], 'risky_paths' => []],
            ),
        );

        $result = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--interactive',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertArrayNotHasKey('error', $result['payload']);
        $this->assertTrue($result['payload']['interactive']['enabled']);
        $this->assertTrue($result['payload']['interactive']['rejected']);
        $this->assertSame([['type' => 'reject']], $result['payload']['interactive']['user_decisions']);
        $this->assertArrayHasKey('original_plan', $result['payload']['interactive']);
        $this->assertTrue($result['payload']['verification_results']['skipped']);
        $this->assertFileDoesNotExist($this->project->root . '/app/features/comments_system/feature.yaml');
    }

    public function test_generate_interactive_reject_aborts_without_writing_files(): void
    {
        $app = $this->interactiveApplication(
            static fn(InteractiveGenerateReviewRequest $request): InteractiveGenerateReviewResult => new InteractiveGenerateReviewResult(
                approved: false,
                plan: $request->plan,
                userDecisions: [['type' => 'reject']],
                preview: ['summary' => [], 'actions' => [], 'diffs' => []],
                risk: ['level' => 'LOW', 'reasons' => ['Plan is additive only.'], 'risky_action_indexes' => [], 'risky_paths' => []],
            ),
        );

        $result = $this->runCommand($app, [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--interactive',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['payload']['interactive']['rejected']);
        $this->assertTrue($result['payload']['verification_results']['skipped']);
        $this->assertFileDoesNotExist($this->project->root . '/app/features/comments_system/feature.yaml');
    }

    public function test_generate_interactive_can_execute_filtered_modify_plan(): void
    {
        $baseline = $this->runCommand(new Application(), [
            'foundry',
            'generate',
            'Create',
            'comments',
            '--mode=new',
            '--json',
        ]);
        $this->assertSame(0, $baseline['status']);

        $feature = (string) $baseline['payload']['plan']['metadata']['feature'];
        $manifestPath = $this->project->root . '/app/features/' . $feature . '/feature.yaml';
        $promptsPath = $this->project->root . '/app/features/' . $feature . '/prompts.md';
        $originalPrompts = (string) file_get_contents($promptsPath);

        $app = $this->interactiveApplication(function (InteractiveGenerateReviewRequest $request) use ($feature): InteractiveGenerateReviewResult {
            $filtered = $this->modifiedPlanWithoutPath($request->plan, 'app/features/' . $feature . '/prompts.md');

            return new InteractiveGenerateReviewResult(
                approved: true,
                plan: $filtered,
                userDecisions: [
                    ['type' => 'exclude_file', 'path' => 'app/features/' . $feature . '/prompts.md'],
                    ['type' => 'approve'],
                ],
                preview: ['summary' => [], 'actions' => [], 'diffs' => []],
                risk: ['level' => 'MEDIUM', 'reasons' => ['Plan modifies existing files.'], 'risky_action_indexes' => [], 'risky_paths' => []],
                modified: true,
            );
        });

        $result = $this->runCommand($app, [
            'foundry',
            'generate',
            'Refine',
            'comments',
            'notes',
            '--mode=modify',
            '--target=' . $feature,
            '--interactive',
            '--json',
        ]);

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['payload']['interactive']['modified']);
        $this->assertSame($originalPrompts, (string) file_get_contents($promptsPath));
        $this->assertStringContainsString('Modification intent: Refine comments notes.', (string) file_get_contents($manifestPath));
        $this->assertCount(1, $result['payload']['actions_taken']);
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = trim((string) ob_get_clean());

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }

    private function fixturePath(string $name): string
    {
        return dirname(__DIR__) . '/Fixtures/Packs/' . $name;
    }

    /**
     * @return array<string,mixed>
     */
    private function fixtureManifest(string $fixtureName): array
    {
        return json_decode((string) file_get_contents($this->fixturePath($fixtureName) . '/foundry.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int,array<string,mixed>> $registryEntries
     * @param array<string,string> $downloads
     */
    private function hostedGenerateApplication(array $registryEntries, array $downloads): Application
    {
        $registryUrl = 'https://registry.example/packs';
        $responses = $downloads + [
            $registryUrl => json_encode($registryEntries, JSON_THROW_ON_ERROR),
        ];

        $fetcher = static function (string $url) use ($responses): string {
            if (!array_key_exists($url, $responses)) {
                throw new \RuntimeException('Unexpected URL: ' . $url);
            }

            return $responses[$url];
        };

        $paths = Paths::fromCwd($this->project->root);
        $registry = new HostedPackRegistry($paths, $fetcher, $registryUrl);
        $manager = new PackManager($paths, $registry);

        return new Application([new GenerateCommand($manager)]);
    }

    /**
     * @param callable(InteractiveGenerateReviewRequest):InteractiveGenerateReviewResult $callback
     */
    private function interactiveApplication(callable $callback): Application
    {
        return new Application([
            new GenerateCommand(
                interactiveReviewerFactory: static function (CommandContext $context) use ($callback): InteractiveGenerateReviewer {
                    return new class ($callback) implements InteractiveGenerateReviewer {
                        public function __construct(private readonly mixed $callback) {}

                        #[\Override]
                        public function review(InteractiveGenerateReviewRequest $request): InteractiveGenerateReviewResult
                        {
                            return ($this->callback)($request);
                        }
                    };
                },
            ),
        ]);
    }

    private function fixtureArchive(string $fixtureName): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'foundry-generate-archive-');
        assert(is_string($archive));

        $zip = new \ZipArchive();
        $opened = $zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $this->assertSame(true, $opened);

        $source = $this->fixturePath($fixtureName);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $relative = substr($fileInfo->getPathname(), strlen(rtrim($source, '/') . '/'));
            $zip->addFile($fileInfo->getPathname(), $relative);
        }

        $zip->close();
        $contents = file_get_contents($archive);
        @unlink($archive);

        return is_string($contents) ? $contents : '';
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
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame(0, $status, trim($stderr) !== '' ? trim($stderr) : trim($stdout));

        return trim($stdout);
    }

    private function modifiedPlanWithoutPath(GenerationPlan $plan, string $excludedPath): GenerationPlan
    {
        $actions = array_values(array_filter(
            $plan->actions,
            static fn(array $action): bool => (string) ($action['path'] ?? '') !== $excludedPath,
        ));
        $affectedFiles = array_values(array_filter(
            $plan->affectedFiles,
            static fn(string $path): bool => $path !== $excludedPath,
        ));

        return new GenerationPlan(
            actions: $actions,
            affectedFiles: $affectedFiles,
            risks: ['Interactive review modified the original plan before execution.'],
            validations: $plan->validations,
            origin: $plan->origin,
            generatorId: $plan->generatorId,
            extension: $plan->extension,
            metadata: $plan->metadata,
            confidence: $plan->confidence,
        );
    }
}
