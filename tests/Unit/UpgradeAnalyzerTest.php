<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\CLI\CommandContext;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\GraphCompiler;
use Foundry\Compiler\Migration\DefinitionMigrator;
use Foundry\Compiler\Migration\ManifestVersionResolver;
use Foundry\Support\Paths;
use Foundry\Upgrade\UpgradeAnalyzer;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class UpgradeAnalyzerTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->seedFeatureManifestV1();
        $this->seedLegacyConfig();
        $this->seedComposerScript();
        $this->seedUpgradeExtension();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_analyzer_reports_deprecations_and_extension_incompatibilities_for_next_stable_target(): void
    {
        $report = (new CommandContext($this->project->root))->upgradeAnalyzer()->analyze('1.0.0');
        $payload = $report->toArray();

        $this->assertFalse($report->ok);
        $this->assertSame('1.0.0', $payload['target_version']);
        $this->assertGreaterThanOrEqual(5, (int) $payload['summary']['total']);

        $codes = array_values(array_map(
            static fn (array $issue): string => (string) ($issue['code'] ?? ''),
            (array) $payload['issues'],
        ));

        $this->assertContains('FDY1704_CONFIG_COMPATIBILITY_ALIAS_USED', $codes);
        $this->assertContains('FDY3001_OUTDATED_FEATURE_MANIFEST', $codes);
        $this->assertContains('FDY1301_DEPRECATED_CLI_USAGE', $codes);
        $this->assertContains('FDY7001_INCOMPATIBLE_EXTENSION_VERSION', $codes);
        $this->assertContains('FDY7008_INCOMPATIBLE_PACK_VERSION', $codes);

        $configIssue = $this->firstIssueByCode((array) $payload['issues'], 'FDY1704_CONFIG_COMPATIBILITY_ALIAS_USED');
        $this->assertNotNull($configIssue);
        $this->assertSame('config/storage.php', $configIssue['affected']['source_path']);
        $this->assertSame('0.4.0', $configIssue['introduced_in']);
        $this->assertSame('1.0.0', $configIssue['target_version']);
        $this->assertStringContainsString('Compatibility aliases keep older config keys working today', (string) $configIssue['why_it_matters']);
        $this->assertStringContainsString('Rename $.local_root to $.root.', (string) $configIssue['migration']);
        $this->assertSame('docs/upgrade-safety.md#config-compatibility-aliases', $configIssue['reference']);
    }

    public function test_analyzer_is_version_aware_for_pre_1_0_target(): void
    {
        $report = (new CommandContext($this->project->root))->upgradeAnalyzer()->analyze('0.4.0');

        $this->assertTrue($report->ok);
        $this->assertSame(0, $report->summary['total']);
        $this->assertSame([], $report->toArray()['issues']);
    }

    public function test_analyzer_uses_default_target_version_and_validates_version_strings(): void
    {
        $context = new CommandContext($this->project->root);
        $analyzer = $context->upgradeAnalyzer();
        $currentVersion = $context->graphCompiler()->frameworkVersion();

        $expectedTarget = ($currentVersion === 'dev-main' || version_compare($currentVersion, '1.0.0', '<'))
            ? '1.0.0'
            : $currentVersion;

        $this->assertSame($expectedTarget, $analyzer->defaultTargetVersion());
        $this->assertTrue($analyzer->isValidTargetVersion('1.0.0'));
        $this->assertTrue($analyzer->isValidTargetVersion('dev-main'));
        $this->assertFalse($analyzer->isValidTargetVersion('1.0.x'));

        $report = $analyzer->analyze();

        $this->assertSame($expectedTarget, $report->targetVersion);
        $this->assertSame('foundry', $report->commandPrefix);
    }

    public function test_analyzer_reports_readme_agents_and_legacy_projection_fallbacks(): void
    {
        file_put_contents($this->project->root . '/README.md', "Run `foundry init app demo-app`.\n");
        file_put_contents($this->project->root . '/AGENTS.md', "Use `foundry init app demo-app` while bootstrapping.\n");
        if (!is_dir($this->project->root . '/app/generated')) {
            mkdir($this->project->root . '/app/generated', 0777, true);
        }
        file_put_contents($this->project->root . '/app/generated/routes.php', <<<'PHP'
<?php
return [
  'POST /legacy' => [
    'feature' => 'publish_post',
    'kind' => 'http',
    'input_schema' => 'app/features/publish_post/input.schema.json',
    'output_schema' => 'app/features/publish_post/output.schema.json',
  ],
];
PHP);

        $report = (new CommandContext($this->project->root))->upgradeAnalyzer()->analyze('1.0.0');
        $payload = $report->toArray();

        $this->assertContains('README.md', $payload['checks']['cli_usage']['scanned_files']);
        $this->assertContains('AGENTS.md', $payload['checks']['cli_usage']['scanned_files']);
        $this->assertContains('composer.json', $payload['checks']['cli_usage']['scanned_files']);

        $cliIssues = array_values(array_filter(
            (array) $payload['issues'],
            static fn (array $issue): bool => (string) ($issue['code'] ?? '') === 'FDY1301_DEPRECATED_CLI_USAGE',
        ));

        $this->assertGreaterThanOrEqual(3, count($cliIssues));
        $this->assertNotNull($this->firstIssueByCode((array) $payload['issues'], 'FDY1302_LEGACY_PROJECTION_FALLBACK'));
    }

    public function test_analyzer_reports_unsupported_manifest_versions_as_blockers(): void
    {
        $manifestPath = $this->project->root . '/app/features/publish_post/feature.yaml';
        $manifest = (string) file_get_contents($manifestPath);
        file_put_contents($manifestPath, str_replace("version: 1\n", "version: 99\n", $manifest));

        $report = (new CommandContext($this->project->root))->upgradeAnalyzer()->analyze('1.0.0');
        $payload = $report->toArray();

        $issue = $this->firstIssueByCode((array) $payload['issues'], 'FDY7003_UNSUPPORTED_DEFINITION_VERSION');
        $this->assertNotNull($issue);
        $this->assertSame('app/features/publish_post/feature.yaml', $issue['affected']['source_path']);
        $this->assertStringContainsString('inspect migrations --json', (string) $issue['migration']);
        $this->assertFalse($report->ok);
    }

    public function test_analyzer_uses_framework_command_prefix_when_project_is_framework_root(): void
    {
        file_put_contents($this->project->root . '/composer.json', <<<'JSON'
{
  "name": "foundry/framework-fixture",
  "version": "1.2.0",
  "require": {
    "php": "^8.4"
  }
}
JSON);

        $paths = new Paths($this->project->root, $this->project->root);
        $extensions = ExtensionRegistry::forPaths($paths);
        $analyzer = new UpgradeAnalyzer(
            paths: $paths,
            compiler: new GraphCompiler($paths, $extensions),
            extensions: $extensions,
            migrator: new DefinitionMigrator(
                $paths,
                new ManifestVersionResolver(),
                $extensions->migrationRules(),
                $extensions->definitionFormats(),
            ),
        );

        $report = $analyzer->analyze();

        $this->assertSame('1.2.0', $analyzer->defaultTargetVersion());
        $this->assertSame('php bin/foundry', $report->commandPrefix);
    }

    private function seedFeatureManifestV1(): void
    {
        $base = $this->project->root . '/app/features/publish_post';
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 1
feature: publish_post
kind: http
description: Publish a post.
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
tests:
  required: [feature]
YAML);

        file_put_contents($base . '/input.schema.json', '{"type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/output.schema.json', '{"type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/action.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/queries.sql', "-- name: insert_post\nINSERT INTO posts(id) VALUES(:id);\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 300\n    invalidated_by: [publish_post]\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit:\n  - name: post.created\n    schema:\n      type: object\n      additionalProperties: false\n      properties: {}\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch:\n  - name: notify_followers\n    input_schema:\n      type: object\n      additionalProperties: false\n      properties: {}\n    queue: default\n    retry:\n      max_attempts: 2\n      backoff_seconds: [1, 2]\n    timeout_seconds: 30\n");
    }

    private function seedLegacyConfig(): void
    {
        mkdir($this->project->root . '/config', 0777, true);

        file_put_contents($this->project->root . '/config/storage.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'local',
    'local_root' => 'storage/files',
];
PHP);
    }

    private function seedComposerScript(): void
    {
        file_put_contents($this->project->root . '/composer.json', <<<'JSON'
{
  "name": "foundry/tests-app",
  "type": "project",
  "require": {
    "php": "^8.4",
    "ext-json": "*",
    "ext-pdo": "*"
  },
  "scripts": {
    "bootstrap-app": "foundry init app demo-app --starter=minimal"
  }
}
JSON);
    }

    private function seedUpgradeExtension(): void
    {
        file_put_contents($this->project->root . '/foundry.extensions.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    \Foundry\Tests\Fixtures\CustomUpgradeExtension::class,
];
PHP);
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     * @return array<string,mixed>|null
     */
    private function firstIssueByCode(array $issues, string $code): ?array
    {
        foreach ($issues as $issue) {
            if ((string) ($issue['code'] ?? '') === $code) {
                return $issue;
            }
        }

        return null;
    }
}
