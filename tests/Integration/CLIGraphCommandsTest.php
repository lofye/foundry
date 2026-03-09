<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIGraphCommandsTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);

        $base = $this->project->root . '/app/features/publish_post';
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 1
feature: publish_post
kind: http
description: test
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
  queries: [insert_post]
cache:
  reads: []
  writes: []
  invalidate: [posts:list]
events:
  emit: [post.created]
  subscribe: []
jobs:
  dispatch: [notify_followers]
rate_limit:
  strategy: user
  bucket: post_create
  cost: 1
tests:
  required: [contract, feature, auth]
llm:
  editable: true
  risk: medium
YAML);

        file_put_contents($base . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/action.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/queries.sql', "-- name: insert_post\nINSERT INTO posts(id) VALUES(:id);\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 300\n    invalidated_by: [publish_post]\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit:\n  - name: post.created\n    schema:\n      type: object\n      additionalProperties: false\n      properties: {}\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch:\n  - name: notify_followers\n    input_schema:\n      type: object\n      additionalProperties: false\n      properties: {}\n    queue: default\n    retry:\n      max_attempts: 2\n      backoff_seconds: [1,2]\n    timeout_seconds: 30\n");
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"publish_post","kind":"http"}');
        file_put_contents($base . '/tests/publish_post_contract_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/publish_post_feature_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/publish_post_auth_test.php', '<?php declare(strict_types=1);');
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_compile_inspect_verify_and_migrate_graph_commands(): void
    {
        $app = new Application();

        $compile = $this->runCommand($app, ['foundry', 'compile', 'graph', '--json']);
        $this->assertSame(0, $compile['status']);
        $this->assertSame('full', $compile['payload']['plan']['mode']);

        $inspectGraph = $this->runCommand($app, ['foundry', 'inspect', 'graph', '--json']);
        $this->assertSame(0, $inspectGraph['status']);
        $this->assertSame(1, $inspectGraph['payload']['graph_version']);

        $inspectNode = $this->runCommand($app, ['foundry', 'inspect', 'node', 'feature:publish_post', '--json']);
        $this->assertSame(0, $inspectNode['status']);
        $this->assertSame('feature', $inspectNode['payload']['node']['type']);

        $inspectDependencies = $this->runCommand($app, ['foundry', 'inspect', 'dependencies', 'feature:publish_post', '--json']);
        $this->assertSame(0, $inspectDependencies['status']);
        $this->assertNotEmpty($inspectDependencies['payload']['dependencies']);

        $inspectDependents = $this->runCommand($app, ['foundry', 'inspect', 'dependents', 'schema:app/features/publish_post/input.schema.json', '--json']);
        $this->assertSame(0, $inspectDependents['status']);

        $inspectPipeline = $this->runCommand($app, ['foundry', 'inspect', 'pipeline', '--json']);
        $this->assertSame(0, $inspectPipeline['status']);
        $this->assertNotEmpty($inspectPipeline['payload']['order']);

        $inspectExecutionPlanFeature = $this->runCommand($app, ['foundry', 'inspect', 'execution-plan', 'publish_post', '--json']);
        $this->assertSame(0, $inspectExecutionPlanFeature['status']);
        $this->assertSame('publish_post', $inspectExecutionPlanFeature['payload']['plan']['payload']['feature']);

        $inspectExecutionPlanRoute = $this->runCommand($app, ['foundry', 'inspect', 'execution-plan', 'POST', '/posts', '--json']);
        $this->assertSame(0, $inspectExecutionPlanRoute['status']);
        $this->assertSame('POST /posts', $inspectExecutionPlanRoute['payload']['plan']['payload']['route_signature']);

        $inspectGuards = $this->runCommand($app, ['foundry', 'inspect', 'guards', 'publish_post', '--json']);
        $this->assertSame(0, $inspectGuards['status']);
        $this->assertNotEmpty($inspectGuards['payload']['guards']);

        $inspectInterceptors = $this->runCommand($app, ['foundry', 'inspect', 'interceptors', '--stage=auth', '--json']);
        $this->assertSame(0, $inspectInterceptors['status']);
        $this->assertIsArray($inspectInterceptors['payload']['interceptors']);

        $impactNode = $this->runCommand($app, ['foundry', 'inspect', 'impact', 'feature:publish_post', '--json']);
        $this->assertSame(0, $impactNode['status']);
        $this->assertArrayHasKey('risk', $impactNode['payload']);

        $impactFile = $this->runCommand($app, ['foundry', 'inspect', 'impact', '--file=app/features/publish_post/feature.yaml', '--json']);
        $this->assertSame(0, $impactFile['status']);
        $this->assertNotEmpty($impactFile['payload']['nodes']);

        $affectedTests = $this->runCommand($app, ['foundry', 'inspect', 'affected-tests', 'feature:publish_post', '--json']);
        $this->assertSame(0, $affectedTests['status']);
        $this->assertContains('publish_post_contract_test', $affectedTests['payload']['tests']);

        $affectedFeatures = $this->runCommand($app, ['foundry', 'inspect', 'affected-features', 'feature:publish_post', '--json']);
        $this->assertSame(0, $affectedFeatures['status']);
        $this->assertContains('publish_post', $affectedFeatures['payload']['features']);

        $extensions = $this->runCommand($app, ['foundry', 'inspect', 'extensions', '--json']);
        $this->assertSame(0, $extensions['status']);
        $this->assertNotEmpty($extensions['payload']['extensions']);
        $this->assertIsArray($extensions['payload']['registration_sources'] ?? null);

        $extension = $this->runCommand($app, ['foundry', 'inspect', 'extension', 'core', '--json']);
        $this->assertSame(0, $extension['status']);
        $this->assertSame('core', $extension['payload']['extension']['name']);

        $packs = $this->runCommand($app, ['foundry', 'inspect', 'packs', '--json']);
        $this->assertSame(0, $packs['status']);
        $this->assertNotEmpty($packs['payload']['packs']);

        $pack = $this->runCommand($app, ['foundry', 'inspect', 'pack', 'core.foundation', '--json']);
        $this->assertSame(0, $pack['status']);
        $this->assertSame('core.foundation', $pack['payload']['pack']['name']);

        $compatibility = $this->runCommand($app, ['foundry', 'inspect', 'compatibility', '--json']);
        $this->assertSame(0, $compatibility['status']);
        $this->assertArrayHasKey('version_matrix', $compatibility['payload']);

        $migrations = $this->runCommand($app, ['foundry', 'inspect', 'migrations', '--json']);
        $this->assertSame(0, $migrations['status']);
        $this->assertNotEmpty($migrations['payload']['rules']);
        $this->assertNotEmpty($migrations['payload']['spec_formats']);
        $this->assertNotEmpty($migrations['payload']['codemods']);

        $specFormat = $this->runCommand($app, ['foundry', 'inspect', 'spec-format', 'feature_manifest', '--json']);
        $this->assertSame(0, $specFormat['status']);
        $this->assertSame('feature_manifest', $specFormat['payload']['spec_format']['name']);

        $verifyGraph = $this->runCommand($app, ['foundry', 'verify', 'graph', '--json']);
        $this->assertSame(0, $verifyGraph['status']);
        $this->assertTrue($verifyGraph['payload']['ok']);

        $verifyPipeline = $this->runCommand($app, ['foundry', 'verify', 'pipeline', '--json']);
        $this->assertSame(0, $verifyPipeline['status']);
        $this->assertTrue($verifyPipeline['payload']['ok']);

        $verifyExtensions = $this->runCommand($app, ['foundry', 'verify', 'extensions', '--json']);
        $this->assertSame(0, $verifyExtensions['status']);
        $this->assertTrue($verifyExtensions['payload']['ok']);

        $verifyCompatibility = $this->runCommand($app, ['foundry', 'verify', 'compatibility', '--json']);
        $this->assertSame(0, $verifyCompatibility['status']);
        $this->assertTrue($verifyCompatibility['payload']['ok']);

        $migrateDryRun = $this->runCommand($app, ['foundry', 'migrate', 'specs', '--dry-run', '--json']);
        $this->assertSame(0, $migrateDryRun['status']);
        $this->assertSame('dry-run', $migrateDryRun['payload']['mode']);

        $migratePathDryRun = $this->runCommand($app, ['foundry', 'migrate', 'specs', '--path=app/features/publish_post/feature.yaml', '--dry-run', '--json']);
        $this->assertSame(0, $migratePathDryRun['status']);
        $this->assertSame('app/features/publish_post/feature.yaml', $migratePathDryRun['payload']['path']);

        $codemodDryRun = $this->runCommand($app, ['foundry', 'codemod', 'run', 'feature-manifest-v1-to-v2', '--dry-run', '--json']);
        $this->assertSame(0, $codemodDryRun['status']);
        $this->assertSame('feature-manifest-v1-to-v2', $codemodDryRun['payload']['codemod']);

        $inspectBuild = $this->runCommand($app, ['foundry', 'inspect', 'build', '--json']);
        $this->assertSame(0, $inspectBuild['status']);
        $this->assertArrayHasKey('manifest', $inspectBuild['payload']);
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
