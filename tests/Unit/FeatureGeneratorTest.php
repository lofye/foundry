<?php
declare(strict_types=1);

namespace Forge\Tests\Unit;

use Forge\Generation\FeatureGenerator;
use Forge\Support\Paths;
use Forge\Tests\Fixtures\TempProject;
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

    public function test_generates_feature_from_spec(): void
    {
        $specPath = $this->project->root . '/publish_post.yaml';
        file_put_contents($specPath, <<<'YAML'
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
        $files = $generator->generateFromSpec($specPath);

        $this->assertNotEmpty($files);
        $this->assertFileExists($this->project->root . '/app/features/publish_post/feature.yaml');
        $this->assertFileExists($this->project->root . '/app/features/publish_post/context.manifest.json');
    }
}
