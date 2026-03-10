<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIPlatformCommandsTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);

        mkdir($this->project->root . '/specs', 0777, true);

        file_put_contents($this->project->root . '/specs/posts.workflow.yaml', <<<'YAML'
version: 1
resource: posts
states: [draft, review, published]
transitions:
  publish:
    from: [review]
    to: published
    permission: posts.publish
YAML);

        file_put_contents($this->project->root . '/specs/process_uploaded_document.orchestration.yaml', <<<'YAML'
version: 1
name: process_uploaded_document
steps:
  - name: extract_text
    job: extract_document_text
  - name: summarize
    job: summarize_document
    depends_on: [extract_text]
YAML);

        file_put_contents($this->project->root . '/specs/posts.search.yaml', <<<'YAML'
version: 1
index: posts
adapter: sql
resource: posts
source:
  table: posts
  primary_key: id
fields: [title, slug]
filters: [status]
YAML);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_platform_commands_generate_inspect_verify_and_render_inspect_ui(): void
    {
        $app = new Application();

        $billing = $this->runCommand($app, ['foundry', 'generate', 'billing', 'stripe', '--json']);
        $this->assertSame(0, $billing['status']);
        $this->assertSame('stripe', $billing['payload']['provider']);

        $workflow = $this->runCommand($app, ['foundry', 'generate', 'workflow', 'posts', '--spec=specs/posts.workflow.yaml', '--json']);
        $this->assertSame(0, $workflow['status']);
        $this->assertSame('posts', $workflow['payload']['workflow']);

        $orchestration = $this->runCommand($app, ['foundry', 'generate', 'orchestration', 'process_uploaded_document', '--spec=specs/process_uploaded_document.orchestration.yaml', '--json']);
        $this->assertSame(0, $orchestration['status']);
        $this->assertSame('process_uploaded_document', $orchestration['payload']['orchestration']);

        $search = $this->runCommand($app, ['foundry', 'generate', 'search-index', 'posts', '--spec=specs/posts.search.yaml', '--json']);
        $this->assertSame(0, $search['status']);
        $this->assertSame('posts', $search['payload']['index']);

        $stream = $this->runCommand($app, ['foundry', 'generate', 'stream', 'job-progress', '--json']);
        $this->assertSame(0, $stream['status']);
        $this->assertSame('job_progress', $stream['payload']['stream']);

        $localeEn = $this->runCommand($app, ['foundry', 'generate', 'locale', 'en', '--json']);
        $this->assertSame(0, $localeEn['status']);

        $localeFr = $this->runCommand($app, ['foundry', 'generate', 'locale', 'fr', '--json']);
        $this->assertSame(0, $localeFr['status']);

        $roles = $this->runCommand($app, ['foundry', 'generate', 'roles', '--json']);
        $this->assertSame(0, $roles['status']);
        $this->assertFileExists($this->project->root . '/app/specs/roles/default.roles.yaml');

        $policy = $this->runCommand($app, ['foundry', 'generate', 'policy', 'posts', '--json']);
        $this->assertSame(0, $policy['status']);
        $this->assertSame('posts', $policy['payload']['policy']);

        $compile = $this->runCommand($app, ['foundry', 'compile', 'graph', '--json']);
        $this->assertSame(0, $compile['status']);

        $inspectBilling = $this->runCommand($app, ['foundry', 'inspect', 'billing', '--json']);
        $this->assertSame(0, $inspectBilling['status']);
        $this->assertNotEmpty($inspectBilling['payload']['billing']);

        $inspectWorkflow = $this->runCommand($app, ['foundry', 'inspect', 'workflow', 'posts', '--json']);
        $this->assertSame(0, $inspectWorkflow['status']);
        $this->assertSame('workflow', $inspectWorkflow['payload']['node']['type']);

        $inspectOrchestration = $this->runCommand($app, ['foundry', 'inspect', 'orchestration', 'process_uploaded_document', '--json']);
        $this->assertSame(0, $inspectOrchestration['status']);
        $this->assertSame('orchestration', $inspectOrchestration['payload']['node']['type']);

        $inspectSearch = $this->runCommand($app, ['foundry', 'inspect', 'search', 'posts', '--json']);
        $this->assertSame(0, $inspectSearch['status']);
        $this->assertSame('search_index', $inspectSearch['payload']['node']['type']);

        $inspectStreams = $this->runCommand($app, ['foundry', 'inspect', 'streams', '--json']);
        $this->assertSame(0, $inspectStreams['status']);
        $this->assertNotEmpty($inspectStreams['payload']['streams']);

        $inspectLocales = $this->runCommand($app, ['foundry', 'inspect', 'locales', '--json']);
        $this->assertSame(0, $inspectLocales['status']);
        $this->assertNotEmpty($inspectLocales['payload']['locales']);

        $inspectRoles = $this->runCommand($app, ['foundry', 'inspect', 'roles', '--json']);
        $this->assertSame(0, $inspectRoles['status']);
        $this->assertNotEmpty($inspectRoles['payload']['roles']);
        $this->assertNotEmpty($inspectRoles['payload']['policies']);

        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'billing', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'workflows', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'orchestrations', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'search', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'streams', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'locales', '--json'])['status']);
        $this->assertSame(0, $this->runCommand($app, ['foundry', 'verify', 'policies', '--json'])['status']);

        $inspectUi = $this->runCommand($app, ['foundry', 'generate', 'inspect-ui', '--json']);
        $this->assertSame(0, $inspectUi['status']);
        $this->assertFileExists($this->project->root . '/docs/inspect-ui/index.html');
        $this->assertFileExists($this->project->root . '/docs/inspect-ui/features.html');
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = ob_get_clean() ?: '';

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }
}
