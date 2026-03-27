<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIArchitectureToolsCommandsTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);

        $this->seedFeature(
            feature: 'publish_post',
            method: 'POST',
            path: '/posts',
            authRequired: true,
            permissions: ['posts.create'],
            emit: ['post.created'],
            subscribe: ['feed.updated'],
            requiredTests: ['feature'],
            createTests: ['feature'],
        );

        $this->seedFeature(
            feature: 'update_feed',
            method: 'POST',
            path: '/feed',
            authRequired: true,
            permissions: [],
            emit: ['feed.updated'],
            subscribe: ['post.created'],
            requiredTests: [],
            createTests: [],
        );

        $this->seedFeature(
            feature: 'list_posts',
            method: 'GET',
            path: '/posts',
            authRequired: false,
            permissions: [],
            emit: [],
            subscribe: [],
            requiredTests: ['feature'],
            createTests: [],
        );

        mkdir($this->project->root . '/app/definitions/workflows', 0777, true);
        file_put_contents($this->project->root . '/app/definitions/workflows/posts.workflow.yaml', <<<'YAML'
version: 1
resource: posts
states: [draft, published]
transitions:
  publish:
    from: [draft]
    to: published
    permission: posts.create
    emit: [post.created]
YAML);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_doctor_visualize_and_prompt_commands_expose_json_contracts(): void
    {
        $app = new Application();

        $compile = $this->runCommand($app, ['foundry', 'compile', 'graph', '--json']);
        $this->assertSame(0, $compile['status']);

        $doctor = $this->runCommand($app, ['foundry', 'doctor', '--feature=list_posts', '--json']);
        $this->assertSame(0, $doctor['status']);
        $this->assertTrue($doctor['payload']['ok']);
        $this->assertSame(0, $doctor['payload']['exit_code']);
        $this->assertSame('foundry', $doctor['payload']['command_prefix']);
        $this->assertSame('list_posts', $doctor['payload']['feature_filter']);
        $this->assertArrayHasKey('checks', $doctor['payload']);
        $this->assertArrayHasKey('analyzers', $doctor['payload']);
        $this->assertArrayHasKey('diagnostics_summary', $doctor['payload']);
        $this->assertArrayHasKey('extension_diagnostics', $doctor['payload']);
        $this->assertArrayHasKey('extension_lifecycle', $doctor['payload']);
        $this->assertArrayHasKey('extension_load_order', $doctor['payload']);

        $doctorStrict = $this->runCommand($app, ['foundry', 'doctor', '--feature=list_posts', '--strict', '--json']);
        $this->assertSame(1, $doctorStrict['status']);
        $this->assertTrue($doctorStrict['payload']['strict']);

        $doctorMissingFeature = $this->runCommand($app, ['foundry', 'doctor', '--feature=missing', '--json']);
        $this->assertSame(1, $doctorMissingFeature['status']);
        $this->assertSame('FEATURE_NOT_FOUND', $doctorMissingFeature['payload']['error']['code']);

        $visualize = $this->runCommand($app, ['foundry', 'graph', 'visualize', '--events', '--format=dot', '--json']);
        $this->assertSame(0, $visualize['status']);
        $this->assertSame('events', $visualize['payload']['view']);
        $this->assertSame('dot', $visualize['payload']['format']);
        $this->assertArrayHasKey('nodes', $visualize['payload']['graph']);
        $this->assertStringContainsString('digraph foundry', (string) $visualize['payload']['rendered']);

        $visualizeBadFormat = $this->runCommand($app, ['foundry', 'graph', 'visualize', '--format=bad', '--json']);
        $this->assertSame(1, $visualizeBadFormat['status']);
        $this->assertSame('CLI_GRAPH_FORMAT_INVALID', $visualizeBadFormat['payload']['error']['code']);

        $visualizeMissingFeature = $this->runCommand($app, ['foundry', 'graph', 'visualize', '--feature=missing', '--json']);
        $this->assertSame(1, $visualizeMissingFeature['status']);
        $this->assertSame('FEATURE_NOT_FOUND', $visualizeMissingFeature['payload']['error']['code']);

        $pipelineViz = $this->runCommand($app, ['foundry', 'graph', 'visualize', '--pipeline', '--json']);
        $this->assertSame(0, $pipelineViz['status']);
        $this->assertSame('pipeline', $pipelineViz['payload']['view']);
        $this->assertArrayHasKey('edges', $pipelineViz['payload']['graph']);

        $pipelineFeatureViz = $this->runCommand($app, ['foundry', 'graph', 'visualize', '--pipeline', '--feature=publish_post', '--json']);
        $this->assertSame(0, $pipelineFeatureViz['status']);
        $this->assertSame('publish_post', $pipelineFeatureViz['payload']['feature_filter']);
        $this->assertNotEmpty($pipelineFeatureViz['payload']['summary']['features']);

        $inspectGraph = $this->runCommand($app, ['foundry', 'inspect', 'graph', '--command', 'POST /posts', '--format=dot', '--json']);
        $this->assertSame(0, $inspectGraph['status']);
        $this->assertSame('command', $inspectGraph['payload']['view']);
        $this->assertSame('POST /posts', $inspectGraph['payload']['command_filter']);
        $this->assertArrayHasKey('summary', $inspectGraph['payload']);
        $this->assertStringContainsString('digraph foundry', (string) $inspectGraph['payload']['rendered']);

        $inspectGraphBadFormat = $this->runCommand($app, ['foundry', 'inspect', 'graph', '--format=bad', '--json']);
        $this->assertSame(1, $inspectGraphBadFormat['status']);
        $this->assertSame('CLI_GRAPH_FORMAT_INVALID', $inspectGraphBadFormat['payload']['error']['code']);

        $inspectGraphMissingFeature = $this->runCommand($app, ['foundry', 'inspect', 'graph', '--feature=missing', '--json']);
        $this->assertSame(1, $inspectGraphMissingFeature['status']);
        $this->assertSame('FEATURE_NOT_FOUND', $inspectGraphMissingFeature['payload']['error']['code']);

        $inspectGraphHuman = $this->runRawCommand($app, ['foundry', 'inspect', 'graph']);
        $this->assertSame(0, $inspectGraphHuman['status']);
        $this->assertStringContainsString('Application graph overview', $inspectGraphHuman['output']);
        $this->assertStringNotContainsString('"graph_version"', $inspectGraphHuman['output']);

        $graphInspect = $this->runCommand($app, ['foundry', 'graph', 'inspect', '--workflow=posts', '--json']);
        $this->assertSame(0, $graphInspect['status']);
        $this->assertSame('workflows', $graphInspect['payload']['view']);
        $this->assertSame('posts', $graphInspect['payload']['workflow_filter']);
        $this->assertContains('posts', $graphInspect['payload']['summary']['workflows']);

        $exportGraph = $this->runCommand($app, ['foundry', 'export', 'graph', '--extension=core', '--format=json', '--json']);
        $this->assertSame(0, $exportGraph['status']);
        $this->assertSame('extensions', $exportGraph['payload']['view']);
        $this->assertSame('core', $exportGraph['payload']['extension_filter']);
        $this->assertFileExists($exportGraph['payload']['file']);
        $this->assertStringContainsString('"view": "extensions"', (string) $exportGraph['payload']['rendered']);

        $exportGraphBadFormat = $this->runCommand($app, ['foundry', 'export', 'graph', '--format=bad', '--json']);
        $this->assertSame(1, $exportGraphBadFormat['status']);
        $this->assertSame('CLI_GRAPH_FORMAT_INVALID', $exportGraphBadFormat['payload']['error']['code']);

        $exportGraphMissingFeature = $this->runCommand($app, ['foundry', 'export', 'graph', '--feature=missing', '--json']);
        $this->assertSame(1, $exportGraphMissingFeature['status']);
        $this->assertSame('FEATURE_NOT_FOUND', $exportGraphMissingFeature['payload']['error']['code']);

        $exportGraphHuman = $this->runRawCommand($app, ['foundry', 'export', 'graph', '--workflow=posts', '--format=json']);
        $this->assertSame(0, $exportGraphHuman['status']);
        $this->assertStringContainsString('Workflow graph for posts', $exportGraphHuman['output']);
        $this->assertStringContainsString('file:', $exportGraphHuman['output']);

        $prompt = $this->runCommand($app, ['foundry', 'prompt', 'Add', 'bookmark', 'support', '--feature-context', '--dry-run', '--json']);
        $this->assertSame(0, $prompt['status']);
        $this->assertTrue($prompt['payload']['dry_run']);
        $this->assertTrue($prompt['payload']['feature_context']);
        $this->assertArrayHasKey('context', $prompt['payload']);
        $this->assertArrayHasKey('prompt', $prompt['payload']);
        $this->assertNotEmpty($prompt['payload']['selected_features']);

        $promptMissingInstruction = $this->runCommand($app, ['foundry', 'prompt', '--json']);
        $this->assertSame(1, $promptMissingInstruction['status']);
        $this->assertSame('CLI_PROMPT_INSTRUCTION_REQUIRED', $promptMissingInstruction['payload']['error']['code']);
    }

    public function test_doctor_loads_extension_registered_checks_and_renders_human_output(): void
    {
        file_put_contents($this->project->root . '/foundry.extensions.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    \Foundry\Tests\Fixtures\CustomDoctorExtension::class,
];
PHP);

        $app = new Application();

        $doctor = $this->runCommand($app, ['foundry', 'doctor', '--feature=list_posts', '--json']);
        $this->assertSame(0, $doctor['status']);
        $this->assertArrayHasKey('fixture_custom_doctor', $doctor['payload']['checks']);

        $codes = array_values(array_map(
            static fn (array $row): string => (string) ($row['code'] ?? ''),
            (array) ($doctor['payload']['doctor_diagnostics']['items'] ?? []),
        ));
        $this->assertContains('FDY9901_FIXTURE_DOCTOR_CHECK', $codes);

        $human = $this->runRawCommand($app, ['foundry', 'doctor', '--feature=list_posts']);
        $this->assertSame(0, $human['status']);
        $this->assertStringContainsString('Foundry doctor completed with warnings.', $human['output']);
        $this->assertStringContainsString('fixture_custom_doctor: warning', $human['output']);
        $this->assertStringContainsString('FDY9901_FIXTURE_DOCTOR_CHECK', $human['output']);
        $this->assertStringNotContainsString('"checks"', $human['output']);
    }

    /**
     * @param array<int,string> $permissions
     * @param array<int,string> $emit
     * @param array<int,string> $subscribe
     * @param array<int,string> $requiredTests
     * @param array<int,string> $createTests
     */
    private function seedFeature(
        string $feature,
        string $method,
        string $path,
        bool $authRequired,
        array $permissions,
        array $emit,
        array $subscribe,
        array $requiredTests,
        array $createTests,
    ): void {
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base . '/tests', 0777, true);

        $permissionsList = '[' . implode(', ', array_map(static fn (string $permission): string => '"' . $permission . '"', $permissions)) . ']';
        $emitList = '[' . implode(', ', array_map(static fn (string $event): string => '"' . $event . '"', $emit)) . ']';
        $subscribeList = '[' . implode(', ', array_map(static fn (string $event): string => '"' . $event . '"', $subscribe)) . ']';
        $testsList = '[' . implode(', ', array_map(static fn (string $kind): string => '"' . $kind . '"', $requiredTests)) . ']';

        file_put_contents($base . '/feature.yaml', <<<YAML
version: 2
feature: {$feature}
kind: http
description: {$feature}
route:
  method: {$method}
  path: {$path}
input:
  schema: app/features/{$feature}/input.schema.json
output:
  schema: app/features/{$feature}/output.schema.json
auth:
  required: {$this->boolString($authRequired)}
  strategies: [bearer]
  permissions: {$permissionsList}
database:
  reads: []
  writes: []
  transactions: required
  queries: []
cache:
  reads: []
  writes: []
  invalidate: [posts:list]
events:
  emit: {$emitList}
  subscribe: {$subscribeList}
jobs:
  dispatch: []
rate_limit: {}
tests:
  required: {$testsList}
llm:
  editable: true
  risk: low
YAML);

        file_put_contents($base . '/input.schema.json', '{"type":"object"}');
        file_put_contents($base . '/output.schema.json', '{"type":"object"}');
        file_put_contents($base . '/queries.sql', '');
        file_put_contents($base . '/permissions.yaml', 'version: 1' . PHP_EOL . 'permissions: ' . $permissionsList . PHP_EOL . 'rules: {}' . PHP_EOL);
        file_put_contents($base . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 120\n    invalidated_by: [{$feature}]\n");

        if ($emit !== [] || $subscribe !== []) {
            $emitRows = [];
            foreach ($emit as $event) {
                $emitRows[] = "  - name: {$event}\n    schema:\n      type: object\n      properties: {}\n      additionalProperties: false";
            }
            $eventsYaml = "version: 1\nemit:\n" . ($emitRows === [] ? "  []\n" : implode("\n", $emitRows) . "\n")
                . 'subscribe: ' . $subscribeList . "\n";
            file_put_contents($base . '/events.yaml', $eventsYaml);
        } else {
            file_put_contents($base . '/events.yaml', "version: 1\nemit: []\nsubscribe: []\n");
        }

        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");

        foreach ($createTests as $kind) {
            file_put_contents($base . '/tests/' . $feature . '_' . $kind . '_test.php', '<?php declare(strict_types=1);');
        }
    }

    private function boolString(bool $value): string
    {
        return $value ? 'true' : 'false';
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

    /**
     * @param array<int,string> $argv
     * @return array{status:int,output:string}
     */
    private function runRawCommand(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = ob_get_clean() ?: '';

        return ['status' => $status, 'output' => $output];
    }
}
