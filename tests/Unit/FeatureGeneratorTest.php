<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generation\FeatureGenerator;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class FeatureGeneratorTest extends TestCase
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

    public function test_generates_feature_from_definition(): void
    {
        $definitionPath = $this->project->root . '/publish_post.yaml';
        file_put_contents($definitionPath, <<<'YAML'
version: 1
feature: publish_post
kind: http
description: Create post
route:
  method: POST
  path: /posts
input:
  fields:
    title:
      type: string
      required: true
output:
  fields:
    id:
      type: string
      required: true
auth:
  required: true
  strategies: [bearer]
  permissions: [posts.create]
database:
  reads: []
  writes: []
  queries: []
  transactions: required
cache:
  invalidate: []
events:
  emit: []
jobs:
  dispatch: []
tests:
  required: [contract, feature, auth]
YAML);

        $generator = new FeatureGenerator(Paths::fromCwd($this->project->root));
        $files = $generator->generateFromDefinition($definitionPath);

        $this->assertNotEmpty($files);
        $this->assertFileExists($this->project->root . '/app/features/publish_post/feature.yaml');
        $this->assertFileExists($this->project->root . '/app/features/publish_post/context.manifest.json');
    }

    public function test_generates_canonical_kebab_case_feature_directory_with_snake_case_code_identifiers(): void
    {
        $generator = new FeatureGenerator(Paths::fromCwd($this->project->root));
        $files = $generator->generateFromArray([
            'version' => 1,
            'feature' => 'context_persistence',
            'canonical_feature' => 'context-persistence',
            'kind' => 'http',
            'description' => 'Persist feature context.',
            'route' => [
                'method' => 'POST',
                'path' => '/context-persistence',
            ],
            'input' => ['fields' => []],
            'output' => ['fields' => []],
            'auth' => [
                'required' => false,
                'strategies' => [],
                'permissions' => [],
            ],
            'database' => ['queries' => []],
            'cache' => ['invalidate' => []],
            'events' => ['emit' => []],
            'jobs' => ['dispatch' => []],
            'tests' => ['required' => ['contract', 'feature']],
        ]);

        $this->assertNotEmpty($files);
        $this->assertFileExists($this->project->root . '/app/features/context-persistence/feature.yaml');
        $this->assertFileExists($this->project->root . '/app/features/context-persistence/context.manifest.json');
        $this->assertFileExists($this->project->root . '/app/features/context-persistence/tests/context_persistence_contract_test.php');
        $this->assertFileDoesNotExist($this->project->root . '/app/features/context_persistence/feature.yaml');
        $this->assertStringContainsString('feature: context-persistence', (string) file_get_contents($this->project->root . '/app/features/context-persistence/feature.yaml'));
    }
}
