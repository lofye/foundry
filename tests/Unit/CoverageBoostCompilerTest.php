<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\InspectGraphCommand;
use Foundry\Compiler\Analysis\ImpactAnalyzer;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\BuildLayout;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\CompilePlanner;
use Foundry\Compiler\GraphCompiler;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\GraphVerifier;
use Foundry\Compiler\IR\AuthNode;
use Foundry\Compiler\IR\CacheNode;
use Foundry\Compiler\IR\EventNode;
use Foundry\Compiler\IR\FeatureNode;
use Foundry\Compiler\IR\JobNode;
use Foundry\Compiler\IR\PermissionNode;
use Foundry\Compiler\IR\QueryNode;
use Foundry\Compiler\IR\RouteNode;
use Foundry\Compiler\IR\SchemaNode;
use Foundry\Compiler\IR\SchedulerNode;
use Foundry\Compiler\IR\WebhookNode;
use Foundry\Compiler\SourceScanner;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CoverageBoostCompilerTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_compile_planner_covers_full_changed_only_feature_and_fallback_paths(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $planner = new CompilePlanner();
        $scanner = new SourceScanner($paths);

        $previousManifest = [
            'source_files' => [
                'app/features/a/feature.yaml' => 'hash-a-old',
                'app/features/c/feature.yaml' => 'hash-c-old',
            ],
            'features' => ['a', 'c'],
            'framework_version' => '1.0.0',
        ];
        $currentHashes = [
            'app/features/a/feature.yaml' => 'hash-a-new',
            'app/features/b/feature.yaml' => 'hash-b-new',
        ];

        $full = $planner->plan(
            options: new CompileOptions(),
            previousManifest: $previousManifest,
            currentSourceHashes: $currentHashes,
            currentFeatures: ['a', 'b'],
            hasPreviousGraph: true,
            scanner: $scanner,
            frameworkVersion: '1.0.0',
        );

        $this->assertSame('full', $full->mode);
        $this->assertFalse($full->incremental);
        $this->assertFalse($full->fallbackToFull);
        $this->assertSame(['a', 'b'], $full->selectedFeatures);
        $this->assertSame(['a', 'b', 'c'], $full->changedFeatures);

        $changedOnly = $planner->plan(
            options: new CompileOptions(changedOnly: true),
            previousManifest: $previousManifest,
            currentSourceHashes: $currentHashes,
            currentFeatures: ['a', 'b'],
            hasPreviousGraph: true,
            scanner: $scanner,
            frameworkVersion: '1.0.0',
        );

        $this->assertSame('changed_only', $changedOnly->mode);
        $this->assertTrue($changedOnly->incremental);
        $this->assertFalse($changedOnly->fallbackToFull);
        $this->assertSame(['a', 'b', 'c'], $changedOnly->selectedFeatures);
        $this->assertStringContainsString('changed feature subgraph', $changedOnly->reason);

        $unchanged = $planner->plan(
            options: new CompileOptions(changedOnly: true),
            previousManifest: [
                'source_files' => $currentHashes,
                'features' => ['a', 'b'],
                'framework_version' => '1.0.0',
            ],
            currentSourceHashes: $currentHashes,
            currentFeatures: ['a', 'b'],
            hasPreviousGraph: true,
            scanner: $scanner,
            frameworkVersion: '1.0.0',
        );

        $this->assertTrue($unchanged->noChanges);
        $this->assertSame([], $unchanged->selectedFeatures);

        $featureMode = $planner->plan(
            options: new CompileOptions(feature: 'a'),
            previousManifest: $previousManifest,
            currentSourceHashes: $currentHashes,
            currentFeatures: ['a', 'b'],
            hasPreviousGraph: true,
            scanner: $scanner,
            frameworkVersion: '1.0.0',
        );

        $this->assertSame('feature', $featureMode->mode);
        $this->assertTrue($featureMode->incremental);
        $this->assertSame(['a', 'b', 'c'], $featureMode->selectedFeatures);
        $this->assertStringContainsString('stale-guard', $featureMode->reason);

        $needsFull = $planner->plan(
            options: new CompileOptions(changedOnly: true),
            previousManifest: [],
            currentSourceHashes: $currentHashes,
            currentFeatures: ['a', 'b'],
            hasPreviousGraph: false,
            scanner: $scanner,
            frameworkVersion: '1.0.0',
        );
        $this->assertTrue($needsFull->fallbackToFull);
        $this->assertStringContainsString('no previous build state', $needsFull->reason);

        $frameworkChanged = $planner->plan(
            options: new CompileOptions(changedOnly: true),
            previousManifest: $previousManifest,
            currentSourceHashes: $currentHashes,
            currentFeatures: ['a', 'b'],
            hasPreviousGraph: true,
            scanner: $scanner,
            frameworkVersion: '2.0.0',
        );
        $this->assertTrue($frameworkChanged->fallbackToFull);
        $this->assertStringContainsString('framework version changed', $frameworkChanged->reason);

        $outsideFeatureChange = $planner->plan(
            options: new CompileOptions(changedOnly: true),
            previousManifest: [
                'source_files' => ['app/platform/config/runtime.yaml' => 'old'],
                'features' => ['a', 'b'],
                'framework_version' => '1.0.0',
            ],
            currentSourceHashes: ['app/platform/config/runtime.yaml' => 'new'],
            currentFeatures: ['a', 'b'],
            hasPreviousGraph: true,
            scanner: $scanner,
            frameworkVersion: '1.0.0',
        );
        $this->assertTrue($outsideFeatureChange->fallbackToFull);
        $this->assertStringContainsString('non-feature source changed', $outsideFeatureChange->reason);
    }

    public function test_graph_verifier_reports_missing_invalid_and_integrity_issues(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $layout = new BuildLayout($paths);
        $verifier = new GraphVerifier($paths, $layout);

        $missing = $verifier->verify();
        $this->assertFalse($missing->ok);
        $this->assertNotEmpty($missing->errors);

        $this->createFeature('publish_post', 'POST', '/posts');
        $compiler = new GraphCompiler($paths);
        $compiler->compile(new CompileOptions());

        $healthy = $verifier->verify();
        $this->assertTrue($healthy->ok);

        $manifestRaw = file_get_contents($layout->compileManifestPath());
        $integrityRaw = file_get_contents($layout->integrityHashesPath());
        $diagnosticsRaw = file_get_contents($layout->diagnosticsPath());
        $this->assertIsString($manifestRaw);
        $this->assertIsString($integrityRaw);
        $this->assertIsString($diagnosticsRaw);

        file_put_contents($layout->compileManifestPath(), '{');
        file_put_contents($layout->diagnosticsPath(), Json::encode([
            'summary' => ['error' => 1, 'warning' => 0, 'info' => 0, 'total' => 1],
            'diagnostics' => [],
        ], true));

        $integrity = Json::decodeAssoc($integrityRaw);
        $firstKey = array_key_first($integrity);
        if (is_string($firstKey) && $firstKey !== '') {
            $integrity[$firstKey] = 'mismatch';
        }
        $integrity['app/.foundry/build/projections/missing_projection.php'] = 'deadbeef';
        file_put_contents($layout->integrityHashesPath(), Json::encode($integrity, true));

        $broken = $verifier->verify();
        $this->assertFalse($broken->ok);
        $this->assertStringContainsString('compile_manifest.json is missing or invalid JSON.', implode("\n", $broken->errors));
        $this->assertStringContainsString('Compiled graph contains error diagnostics.', implode("\n", $broken->errors));
        $this->assertStringContainsString('Integrity mismatch detected', implode("\n", $broken->warnings));
        $this->assertStringContainsString('Integrity file references missing artifact', implode("\n", $broken->warnings));
    }

    public function test_impact_analyzer_and_application_graph_cover_high_medium_low_risk_paths(): void
    {
        $graph = new ApplicationGraph(1, 'dev', gmdate(DATE_ATOM), 'source-hash');

        for ($i = 1; $i <= 5; $i++) {
            $feature = 'f' . $i;
            $graph->addNode(new FeatureNode(
                id: 'feature:' . $feature,
                sourcePath: 'app/features/' . $feature . '/feature.yaml',
                payload: [
                    'feature' => $feature,
                    'tests' => ['required' => ['contract', 'feature']],
                ],
            ));
            $graph->addEdge(GraphEdge::make('feature_to_route', 'feature:' . $feature, 'route:GET:/posts'));
        }

        $graph->addNode(new RouteNode('route:GET:/posts', 'app/features/f1/feature.yaml', ['signature' => 'GET /posts', 'feature' => 'f1']));
        $graph->addNode(new SchemaNode('schema:app/features/f1/input.schema.json', 'app/features/f1/input.schema.json', ['path' => 'app/features/f1/input.schema.json', 'role' => 'input', 'feature' => 'f1']));
        $graph->addNode(new SchemaNode('schema:app/features/f1/output.schema.json', 'app/features/f1/output.schema.json', ['path' => 'app/features/f1/output.schema.json', 'role' => 'output', 'feature' => 'f1']));
        $graph->addNode(new AuthNode('auth:f1', 'app/features/f1/feature.yaml', ['feature' => 'f1']));
        $graph->addNode(new PermissionNode('permission:posts.create', 'app/features/f1/permissions.yaml', ['name' => 'posts.create', 'feature' => 'f1']));
        $graph->addNode(new EventNode('event:post.created', 'app/features/f1/events.yaml', ['name' => 'post.created', 'feature' => 'f1']));
        $graph->addNode(new JobNode('job:notify_followers', 'app/features/f1/jobs.yaml', ['name' => 'notify_followers', 'feature' => 'f1']));
        $graph->addNode(new CacheNode('cache:posts:list', 'app/features/f1/cache.yaml', ['key' => 'posts:list', 'feature' => 'f1']));
        $graph->addNode(new SchedulerNode('scheduler:f1:cleanup', 'app/features/f1/scheduler.yaml', ['feature' => 'f1', 'name' => 'cleanup']));
        $graph->addNode(new WebhookNode('webhook:incoming:f1:github', 'app/features/f1/webhooks.yaml', ['feature' => 'f1', 'name' => 'github']));
        $graph->addNode(new QueryNode('query:f1:insert_post', 'app/features/f1/queries.sql', ['feature' => 'f1', 'name' => 'insert_post']));

        $graph->addEdge(GraphEdge::make('feature_to_input_schema', 'feature:f1', 'schema:app/features/f1/input.schema.json'));
        $graph->addEdge(GraphEdge::make('feature_to_output_schema', 'feature:f1', 'schema:app/features/f1/output.schema.json'));
        $graph->addEdge(GraphEdge::make('feature_to_auth_config', 'feature:f1', 'auth:f1'));
        $graph->addEdge(GraphEdge::make('feature_to_permission', 'feature:f1', 'permission:posts.create'));
        $graph->addEdge(GraphEdge::make('feature_to_event_emit', 'feature:f1', 'event:post.created'));
        $graph->addEdge(GraphEdge::make('feature_to_job_dispatch', 'feature:f1', 'job:notify_followers'));
        $graph->addEdge(GraphEdge::make('feature_to_cache_invalidation', 'feature:f1', 'cache:posts:list'));
        $graph->addEdge(GraphEdge::make('feature_to_scheduler_task', 'feature:f1', 'scheduler:f1:cleanup'));
        $graph->addEdge(GraphEdge::make('feature_to_webhook', 'feature:f1', 'webhook:incoming:f1:github'));
        $graph->addEdge(GraphEdge::make('feature_to_query', 'feature:f1', 'query:f1:insert_post'));

        $analyzer = new ImpactAnalyzer(Paths::fromCwd($this->project->root));

        $missing = $analyzer->reportForNode($graph, 'feature:missing');
        $this->assertTrue((bool) ($missing['missing'] ?? false));
        $this->assertSame('high', $missing['risk']);

        $routeReport = $analyzer->reportForNode($graph, 'route:GET:/posts');
        $this->assertSame('high', $routeReport['risk']);
        $this->assertContains('routes_index.php', $routeReport['affected_projections']);
        $this->assertCount(5, $routeReport['affected_features']);

        $inputSchemaReport = $analyzer->reportForNode($graph, 'schema:app/features/f1/input.schema.json');
        $this->assertSame('high', $inputSchemaReport['risk']);
        $this->assertContains('php vendor/bin/foundry verify contracts --json', $inputSchemaReport['recommended_verification']);

        $outputSchemaReport = $analyzer->reportForNode($graph, 'schema:app/features/f1/output.schema.json');
        $this->assertSame('medium', $outputSchemaReport['risk']);

        $authReport = $analyzer->reportForNode($graph, 'auth:f1');
        $this->assertSame('high', $authReport['risk']);
        $this->assertContains('php vendor/bin/foundry verify auth --json', $authReport['recommended_verification']);

        $cacheReport = $analyzer->reportForNode($graph, 'cache:posts:list');
        $this->assertContains('php vendor/bin/foundry verify cache --json', $cacheReport['recommended_verification']);
        $eventReport = $analyzer->reportForNode($graph, 'event:post.created');
        $this->assertContains('php vendor/bin/foundry verify events --json', $eventReport['recommended_verification']);
        $jobReport = $analyzer->reportForNode($graph, 'job:notify_followers');
        $this->assertContains('php vendor/bin/foundry verify jobs --json', $jobReport['recommended_verification']);

        $unknownFile = $analyzer->reportForFile($graph, 'app/features/none/feature.yaml');
        $this->assertSame('low', $unknownFile['risk']);
        $this->assertSame('No graph nodes mapped to file.', $unknownFile['message']);

        $absolutePathFile = $analyzer->reportForFile($graph, $this->project->root . '/app/features/f1/feature.yaml');
        $this->assertSame('app/features/f1/feature.yaml', $absolutePathFile['file']);
        $this->assertNotEmpty($absolutePathFile['nodes']);

        $this->assertContains('f1_contract_test', $analyzer->affectedTests($graph, 'feature:f1'));
        $this->assertContains('f1', $analyzer->affectedFeatures($graph, 'feature:f1'));

        $row = $graph->toArray(new \Foundry\Compiler\Diagnostics\DiagnosticBag());
        $restored = ApplicationGraph::fromArray($row);
        $this->assertTrue($restored->hasNode('feature:f1'));

        $restored->removeFeature('f1');
        $this->assertFalse($restored->hasNode('feature:f1'));
    }

    public function test_inspect_graph_command_covers_error_and_option_parsing_branches(): void
    {
        $command = new InspectGraphCommand();
        $context = new CommandContext($this->project->root);

        $build = $command->run(['inspect', 'build'], $context);
        $this->assertSame(1, $build['status']);

        try {
            $command->run(['inspect', 'node'], $context);
            $this->fail('Expected missing node ID failure.');
        } catch (FoundryError $error) {
            $this->assertSame('CLI_NODE_REQUIRED', $error->errorCode);
        }

        try {
            $command->run(['inspect', 'dependencies', 'feature:missing'], $context);
            $this->fail('Expected unknown node failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GRAPH_NODE_NOT_FOUND', $error->errorCode);
        }

        try {
            $command->run(['inspect', 'impact'], $context);
            $this->fail('Expected missing impact target failure.');
        } catch (FoundryError $error) {
            $this->assertSame('CLI_IMPACT_TARGET_REQUIRED', $error->errorCode);
        }

        $impactByFile = $command->run(['inspect', 'impact', '--file', 'app/features/none/feature.yaml'], $context);
        $this->assertSame(0, $impactByFile['status']);
        $this->assertSame('app/features/none/feature.yaml', $impactByFile['payload']['file']);
    }

    private function createFeature(string $feature, string $method, string $path): void
    {
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base, 0777, true);

        file_put_contents($base . '/feature.yaml', <<<YAML
version: 1
feature: {$feature}
kind: http
description: test
route:
  method: {$method}
  path: {$path}
input:
  schema: app/features/{$feature}/input.schema.json
output:
  schema: app/features/{$feature}/output.schema.json
auth:
  required: false
  strategies: []
  permissions: []
database:
  reads: []
  writes: []
  transactions: required
  queries: []
cache:
  reads: []
  writes: []
  invalidate: []
events:
  emit: []
  subscribe: []
jobs:
  dispatch: []
rate_limit:
  strategy: user
  bucket: {$feature}
  cost: 1
tests:
  required: [contract]
llm:
  editable: true
  risk: low
YAML);

        file_put_contents($base . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
    }
}
