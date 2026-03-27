<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Codemod\CodemodEngine;
use Foundry\Compiler\Codemod\FeatureManifestV2Codemod;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CodemodEngineTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();

        $feature = $this->project->root . '/app/features/publish_post';
        mkdir($feature, 0777, true);
        file_put_contents($feature . '/feature.yaml', <<<'YAML'
version: 1
feature: publish_post
kind: http
route:
  method: post
  path: /posts
auth:
  strategy: bearer
  permissions: [posts.create]
llm:
  risk: medium
YAML);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_codemod_engine_runs_dry_run_and_write_modes(): void
    {
        $engine = new CodemodEngine(
            paths: Paths::fromCwd($this->project->root),
            codemods: [new FeatureManifestV2Codemod()],
        );

        $dryRun = $engine->run('feature-manifest-v1-to-v2', false, 'app/features/publish_post/feature.yaml');
        $this->assertFalse($dryRun->written);
        $this->assertCount(1, $dryRun->changes);
        $this->assertSame('feature-manifest-v1-to-v2', $dryRun->codemod);

        $write = $engine->run('feature-manifest-v1-to-v2', true, 'app/features/publish_post/feature.yaml');
        $this->assertTrue($write->written);
        $this->assertCount(1, $write->changes);

        $manifest = Yaml::parseFile($this->project->root . '/app/features/publish_post/feature.yaml');
        $this->assertSame(2, $manifest['version']);
        $this->assertSame(['bearer'], $manifest['auth']['strategies']);
        $this->assertSame('medium', $manifest['llm']['risk_level']);
    }

    public function test_codemod_engine_reports_missing_codemods_and_inspection(): void
    {
        $engine = new CodemodEngine(
            paths: Paths::fromCwd($this->project->root),
            codemods: [new FeatureManifestV2Codemod()],
        );

        $rows = $engine->inspectRows();
        $this->assertCount(1, $rows);
        $this->assertSame('feature-manifest-v1-to-v2', $rows[0]['id']);
        $this->assertTrue($engine->has('feature-manifest-v1-to-v2'));
        $this->assertFalse($engine->has('missing-codemod'));

        $this->expectException(FoundryError::class);
        $engine->run('missing-codemod', false);
    }
}
