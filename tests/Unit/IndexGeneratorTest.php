<?php
declare(strict_types=1);

namespace Forge\Tests\Unit;

use Forge\Generation\IndexGenerator;
use Forge\Support\Paths;
use Forge\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class IndexGeneratorTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();

        $featurePath = $this->project->root . '/app/features/publish_post';
        mkdir($featurePath, 0777, true);

        file_put_contents($featurePath . '/feature.yaml', <<<'YAML'
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
  required: [contract, feature, auth]
llm:
  editable: true
  risk: medium
YAML);

        file_put_contents($featurePath . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($featurePath . '/events.yaml', "version: 1\nemit: []\nsubscribe: []\n");
        file_put_contents($featurePath . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($featurePath . '/cache.yaml', "version: 1\nentries: []\n");
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_generates_required_indexes(): void
    {
        $generator = new IndexGenerator(Paths::fromCwd($this->project->root));
        $files = $generator->generate();

        $this->assertCount(9, $files);
        $this->assertFileExists($this->project->root . '/app/generated/routes.php');

        $routes = require $this->project->root . '/app/generated/routes.php';
        $this->assertArrayHasKey('POST /posts', $routes);
    }
}
