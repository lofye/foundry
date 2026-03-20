<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\BuildLayout;
use Foundry\Compiler\CompileCacheInspector;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\GraphCompiler;
use Foundry\Compiler\SourceScanner;
use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CompileCacheInspectorTest extends TestCase
{
    private TempProject $project;
    private Paths $paths;
    private SourceScanner $scanner;
    private BuildLayout $layout;
    private CompileCacheInspector $inspector;
    private ExtensionRegistry $extensions;
    private GraphCompiler $compiler;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->paths = Paths::fromCwd($this->project->root);
        $this->scanner = new SourceScanner($this->paths);
        $this->layout = new BuildLayout($this->paths);
        $this->inspector = new CompileCacheInspector($this->paths, $this->layout, $this->scanner);
        $this->extensions = ExtensionRegistry::forPaths($this->paths);
        $this->compiler = new GraphCompiler($this->paths, $this->extensions);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_current_state_collects_stable_compile_inputs(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');

        $sourceFiles = $this->scanner->sourceFiles();
        $sourceHashes = $this->scanner->hashFiles($sourceFiles);
        $compatibility = $this->extensions
            ->compatibilityReport($this->compiler->frameworkVersion(), GraphCompiler::GRAPH_VERSION)
            ->toArray();

        $state = $this->inspector->currentState(
            sourceHashes: $sourceHashes,
            extensions: $this->extensions,
            compatibility: $compatibility,
            frameworkVersion: $this->compiler->frameworkVersion(),
            graphVersion: GraphCompiler::GRAPH_VERSION,
        );

        $this->assertSame(1, $state['schema_version']);
        $this->assertArrayHasKey('key', $state);
        $this->assertArrayHasKey('feature_manifest_hash', $state['inputs']);
        $this->assertArrayHasKey('schema_hash', $state['inputs']);
        $this->assertArrayHasKey('feature_source_hash', $state['inputs']);
        $this->assertArrayHasKey('platform_config_hash', $state['inputs']);
        $this->assertArrayHasKey('extension_metadata_hash', $state['inputs']);
        $this->assertArrayHasKey('framework_source_hash', $state['inputs']);
    }

    public function test_inspect_reports_disabled_and_missing_previous_build_states(): void
    {
        $disabled = $this->inspect(new CompileOptions(useCache: false));
        $this->assertSame('disabled', $disabled['status']);

        $missing = $this->inspect();
        $this->assertSame('miss', $missing['status']);
        $this->assertTrue($missing['requires_full_recompile']);
    }

    public function test_inspect_detects_missing_metadata_schema_change_and_missing_artifacts(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');
        $this->compiler->compile(new CompileOptions());

        unlink($this->layout->compileCachePath());
        $missingMetadata = $this->inspect();
        $this->assertSame('miss', $missingMetadata['status']);
        $this->assertContains('cache_metadata', $missingMetadata['invalidated_inputs']);

        $this->compiler->compile(new CompileOptions());

        $cache = $this->readJson($this->layout->compileCachePath());
        $cache['schema_version'] = 999;
        file_put_contents($this->layout->compileCachePath(), Json::encode($cache, true));

        $schemaChanged = $this->inspect();
        $this->assertSame('miss', $schemaChanged['status']);
        $this->assertContains('cache_schema', $schemaChanged['invalidated_inputs']);

        $this->compiler->compile(new CompileOptions());

        unlink($this->layout->projectionPath('routes_index.php'));
        $missingArtifact = $this->inspect();
        $this->assertSame('miss', $missingArtifact['status']);
        $this->assertContains('compiled_artifacts', $missingArtifact['invalidated_inputs']);
    }

    public function test_inspect_distinguishes_incremental_and_full_rebuild_invalidations(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');
        $this->compiler->compile(new CompileOptions());

        $cache = $this->readJson($this->layout->compileCachePath());
        $cache['inputs']['feature_manifest_hash'] = 'stale-feature-hash';
        file_put_contents($this->layout->compileCachePath(), Json::encode($cache, true));

        $incremental = $this->inspect();
        $this->assertSame('invalidated', $incremental['status']);
        $this->assertContains('feature_manifest_hash', $incremental['invalidated_inputs']);
        $this->assertFalse($incremental['requires_full_recompile']);

        $this->compiler->compile(new CompileOptions());

        $cache = $this->readJson($this->layout->compileCachePath());
        $cache['inputs']['framework_source_hash'] = 'stale-framework-hash';
        file_put_contents($this->layout->compileCachePath(), Json::encode($cache, true));

        $full = $this->inspect();
        $this->assertSame('invalidated', $full['status']);
        $this->assertContains('framework_source_hash', $full['invalidated_inputs']);
        $this->assertTrue($full['requires_full_recompile']);
    }

    public function test_clear_removes_build_and_generated_artifacts(): void
    {
        $this->createFeature('publish_post', 'POST', '/posts');
        $this->compiler->compile(new CompileOptions());

        $result = $this->inspector->clear();

        $this->assertTrue($result['cleared']);
        $this->assertFileDoesNotExist($this->layout->compileManifestPath());
        $this->assertFileDoesNotExist($this->paths->generated() . '/routes.php');
    }

    /**
     * @return array<string,mixed>
     */
    private function inspect(CompileOptions $options = new CompileOptions()): array
    {
        $sourceFiles = $this->scanner->sourceFiles();
        $sourceHashes = $this->scanner->hashFiles($sourceFiles);
        $compatibility = $this->extensions
            ->compatibilityReport($this->compiler->frameworkVersion(), GraphCompiler::GRAPH_VERSION)
            ->toArray();

        return $this->inspector->inspect(
            options: $options,
            sourceHashes: $sourceHashes,
            previousManifest: $this->readJson($this->layout->compileManifestPath(), []),
            previousGraph: $this->compiler->loadGraph(),
            extensions: $this->extensions,
            compatibility: $compatibility,
            frameworkVersion: $this->compiler->frameworkVersion(),
            graphVersion: GraphCompiler::GRAPH_VERSION,
        );
    }

    /**
     * @param array<string,mixed> $default
     * @return array<string,mixed>
     */
    private function readJson(string $path, array $default = []): array
    {
        if (!is_file($path)) {
            return $default;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return $default;
        }

        return Json::decodeAssoc($raw);
    }

    private function createFeature(string $feature, string $method, string $path): void
    {
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base . '/tests', 0777, true);

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
tests:
  required: [contract, feature, auth]
YAML);

        file_put_contents($base . '/action.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/queries.sql', "-- name: insert_post\nINSERT INTO posts(id) VALUES(:id);\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 300\n    invalidated_by: [{$feature}]\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit:\n  - name: post.created\n    schema:\n      type: object\n      additionalProperties: false\n      properties: {}\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch:\n  - name: notify_followers\n    input_schema:\n      type: object\n      additionalProperties: false\n      properties: {}\n    queue: default\n");
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"' . $feature . '","kind":"http"}');
        file_put_contents($base . '/tests/' . $feature . '_contract_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/' . $feature . '_feature_test.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/tests/' . $feature . '_auth_test.php', '<?php declare(strict_types=1);');
    }
}
