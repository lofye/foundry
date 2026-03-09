<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class PipelineCompilerIntegrationTest extends TestCase
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

    public function test_compiler_emits_pipeline_nodes_execution_plans_and_guards(): void
    {
        $this->createFeature(
            feature: 'publish_post',
            method: 'POST',
            path: '/posts',
            authRequired: true,
            rateLimitBody: "rate_limit:\n  strategy: user\n  bucket: post_create\n  cost: 1\n",
        );

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $result = $compiler->compile(new CompileOptions());
        $graph = $result->graph;

        $this->assertNotEmpty($graph->nodesByType('pipeline_stage'));
        $this->assertNotEmpty($graph->nodesByType('execution_plan'));
        $this->assertNotEmpty($graph->nodesByType('guard'));
        $this->assertNotEmpty($graph->nodesByType('interceptor'));

        $this->assertNotNull($graph->node('execution_plan:feature:publish_post'));
        $this->assertNotNull($graph->node('guard:auth:publish_post'));

        $edgeTypes = array_values(array_unique(array_map(
            static fn ($edge): string => $edge->type,
            $graph->edges(),
        )));
        $this->assertContains('pipeline_stage_next', $edgeTypes);
        $this->assertContains('feature_to_execution_plan', $edgeTypes);
        $this->assertContains('execution_plan_to_guard', $edgeTypes);
    }

    public function test_compiler_reports_pipeline_diagnostics_for_unguarded_and_conflicting_rate_limits(): void
    {
        $this->createFeature(
            feature: 'create_post',
            method: 'POST',
            path: '/posts',
            authRequired: false,
            rateLimitBody: "rate_limit:\n  bucket: a\n  buckets: [a, b]\n  strategy: user\n",
        );

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $result = $compiler->compile(new CompileOptions());

        $codes = array_values(array_unique(array_map(
            static fn (array $row): string => (string) ($row['code'] ?? ''),
            $result->diagnostics->toArray(),
        )));

        $this->assertContains('FDY8001_FEATURE_REQUIRES_AUTH', $codes);
        $this->assertContains('FDY8003_CONFLICTING_RATE_LIMIT', $codes);
    }

    private function createFeature(
        string $feature,
        string $method,
        string $path,
        bool $authRequired,
        string $rateLimitBody,
    ): void {
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<YAML
version: 2
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
  required: {$this->boolString($authRequired)}
  strategies: [bearer]
  permissions: []
database:
  reads: []
  writes: [posts]
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
{$rateLimitBody}tests:
  required: [feature]
llm:
  editable: true
  risk_level: medium
YAML);

        file_put_contents($base . '/input.schema.json', '{"type":"object"}');
        file_put_contents($base . '/output.schema.json', '{"type":"object"}');
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: []\nrules: {}\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit: []\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries: []\n");
    }

    private function boolString(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
