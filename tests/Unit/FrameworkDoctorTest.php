<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Analysis\ArchitectureDoctor;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Doctor\Checks\GraphIntegrityCheck;
use Foundry\Doctor\Checks\MetadataFreshnessCheck;
use Foundry\Doctor\Checks\PipelineConsistencyCheck;
use Foundry\Doctor\Checks\RuntimeCompatibilityCheck;
use Foundry\Doctor\DoctorContext;
use Foundry\Doctor\FrameworkDoctor;
use Foundry\Pipeline\PipelineIntegrityInspector;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class FrameworkDoctorTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->seedFeature();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_framework_doctor_reports_runtime_and_context_freshness_failures(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $compiler = new GraphCompiler($paths);
        $compileResult = $compiler->compile(new CompileOptions());
        $base = $this->project->root . '/app/features/publish_post';

        $contextMtime = filemtime($base . '/context.manifest.json') ?: time();
        touch($base . '/feature.yaml', $contextMtime + 20);

        $report = $this->doctor([
            new MetadataFreshnessCheck(),
            new RuntimeCompatibilityCheck(
                phpVersionResolver: static fn (): string => '8.3.99',
                extensionLoadedResolver: static fn (string $extension): bool => $extension !== 'pdo',
            ),
        ], $compiler, $compileResult)->diagnose($this->doctorContext($compiler, $compileResult));

        $codes = array_values(array_map(
            static fn (array $row): string => (string) ($row['code'] ?? ''),
            (array) ($report['diagnostics']['items'] ?? []),
        ));
        sort($codes);

        $this->assertContains('FDY9101_PHP_VERSION_UNSUPPORTED', $codes);
        $this->assertContains('FDY9102_REQUIRED_EXTENSION_MISSING', $codes);
        $this->assertContains('FDY9114_CONTEXT_MANIFEST_STALE', $codes);
        $this->assertSame(['pdo'], $report['checks']['runtime_compatibility']['result']['missing_extensions']);
        $this->assertContains('publish_post', $report['checks']['metadata_freshness']['result']['stale_context_features']);
    }

    public function test_framework_doctor_reports_graph_integrity_and_pipeline_failures(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $compiler = new GraphCompiler($paths);
        $compileResult = $compiler->compile(new CompileOptions());

        @unlink($this->project->root . '/app/.foundry/build/graph/app_graph.json');
        $compileResult->graph->removeNode('execution_plan:feature:publish_post');

        $report = $this->doctor([
            new GraphIntegrityCheck(),
            new PipelineConsistencyCheck(new PipelineIntegrityInspector()),
        ], $compiler, $compileResult)->diagnose($this->doctorContext($compiler, $compileResult));

        $codes = array_values(array_map(
            static fn (array $row): string => (string) ($row['code'] ?? ''),
            (array) ($report['diagnostics']['items'] ?? []),
        ));
        sort($codes);

        $this->assertContains('FDY9109_BUILD_ARTIFACT_MISSING', $codes);
        $this->assertContains('FDY9117_EXECUTION_PLANS_MISSING', $codes);
        $this->assertContains('FDY9118_ROUTE_EXECUTION_PLAN_MISSING', $codes);
        $this->assertContains('FDY9119_FEATURE_EXECUTION_PLAN_MISSING', $codes);
        $this->assertSame('error', $report['checks']['graph_integrity']['result']['status']);
        $this->assertSame('error', $report['checks']['pipeline_consistency']['result']['status']);
    }

    /**
     * @param array<int,\Foundry\Doctor\DoctorCheck> $checks
     */
    private function doctor(array $checks, GraphCompiler $compiler, \Foundry\Compiler\CompileResult $compileResult): FrameworkDoctor
    {
        return new FrameworkDoctor(
            checks: $checks,
            architectureDoctor: new ArchitectureDoctor(
                analyzers: [],
                impactAnalyzer: $compiler->impactAnalyzer(),
                commandPrefix: 'foundry',
            ),
        );
    }

    private function doctorContext(GraphCompiler $compiler, \Foundry\Compiler\CompileResult $compileResult): DoctorContext
    {
        $paths = Paths::fromCwd($this->project->root);
        $extensionRegistry = $compiler->extensionRegistry();
        $extensionReport = $extensionRegistry->compatibilityReport(
            frameworkVersion: $compileResult->graph->frameworkVersion(),
            graphVersion: $compileResult->graph->graphVersion(),
        );

        /** @var array<string,mixed> $composerConfig */
        $composerConfig = json_decode((string) file_get_contents($this->project->root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        return new DoctorContext(
            paths: $paths,
            layout: $compiler->buildLayout(),
            compileResult: $compileResult,
            extensionRegistry: $extensionRegistry,
            extensionReport: $extensionReport,
            featureFilter: null,
            commandPrefix: 'foundry',
            composerPath: $this->project->root . '/composer.json',
            composerConfig: $composerConfig,
        );
    }

    private function seedFeature(): void
    {
        $base = $this->project->root . '/app/features/publish_post';
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 2
feature: publish_post
kind: http
description: publish
route:
  method: POST
  path: /posts
input:
  schema: app/features/publish_post/input.schema.json
output:
  schema: app/features/publish_post/output.schema.json
auth:
  required: true
  strategies: [bearer]
  permissions: [posts.create]
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
rate_limit: {}
tests:
  required: [feature]
llm:
  editable: true
  risk: low
YAML);

        file_put_contents($base . '/input.schema.json', '{"type":"object","properties":{"title":{"type":"string"}}}');
        file_put_contents($base . '/output.schema.json', '{"type":"object","properties":{"id":{"type":"string"}}}');
        file_put_contents($base . '/events.yaml', "version: 1\nemit: []\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries: []\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules:\n  admin: [posts.create]\n");
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"publish_post","kind":"http"}');
        file_put_contents($base . '/tests/publish_post_feature_test.php', '<?php declare(strict_types=1);');
    }
}
