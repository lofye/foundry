<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Migration\DefinitionMigrator;
use Foundry\Compiler\Migration\FeatureManifestV2Rule;
use Foundry\Compiler\Migration\ManifestVersionResolver;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class DefinitionMigratorTest extends TestCase
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
  editable: true
  risk: medium
YAML);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_migrate_definitions_dry_run_and_write_modes(): void
    {
        $migrator = new DefinitionMigrator(
            Paths::fromCwd($this->project->root),
            new ManifestVersionResolver(),
            [new FeatureManifestV2Rule()],
        );

        $dryRun = $migrator->migrate(false);
        $this->assertFalse($dryRun->written);
        $this->assertCount(1, $dryRun->changes);
        $this->assertSame('FDY_MIGRATE_FEATURE_MANIFEST_V2', $dryRun->changes[0]['rules'][0]);
        $this->assertSame('dry-run', $dryRun->toArray()['mode']);

        $write = $migrator->migrate(true);
        $this->assertTrue($write->written);
        $this->assertCount(1, $write->changes);

        $manifest = Yaml::parseFile($this->project->root . '/app/features/publish_post/feature.yaml');
        $this->assertSame(2, $manifest['version']);
        $this->assertSame(['bearer'], $manifest['auth']['strategies']);
        $this->assertSame('POST', $manifest['route']['method']);
        $this->assertSame('medium', $manifest['llm']['risk_level']);
    }

    public function test_migrate_definitions_supports_path_filter(): void
    {
        $migrator = new DefinitionMigrator(
            Paths::fromCwd($this->project->root),
            new ManifestVersionResolver(),
            [new FeatureManifestV2Rule()],
        );

        $result = $migrator->migrate(false, 'app/features/publish_post/feature.yaml');
        $this->assertSame('app/features/publish_post/feature.yaml', $result->pathFilter);
        $this->assertCount(1, $result->changes);
        $this->assertNotEmpty($result->plans);
    }

    public function test_inspect_returns_rule_metadata(): void
    {
        $migrator = new DefinitionMigrator(
            Paths::fromCwd($this->project->root),
            new ManifestVersionResolver(),
            [new FeatureManifestV2Rule()],
        );

        $rules = $migrator->inspect();
        $this->assertCount(1, $rules);
        $this->assertSame('FDY_MIGRATE_FEATURE_MANIFEST_V2', $rules[0]['id']);
        $this->assertSame(1, $rules[0]['from_version']);
        $this->assertSame(2, $rules[0]['to_version']);

        $formats = $migrator->definitionFormats();
        $this->assertSame('feature_manifest', $formats[0]['name']);
        $this->assertSame(2, $formats[0]['current_version']);
        $this->assertSame('feature_manifest', $migrator->definitionFormat('feature_manifest')['name']);
        $this->assertNull($migrator->definitionFormat('missing_format'));
    }
}
