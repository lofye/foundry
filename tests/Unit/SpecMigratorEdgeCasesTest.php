<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Compiler\Migration\ManifestVersionResolver;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Migration\SpecFormat;
use Foundry\Compiler\Migration\SpecMigrator;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class SpecMigratorEdgeCasesTest extends TestCase
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

    public function test_migrate_reports_missing_path_and_parse_failures_with_diagnostics_bag(): void
    {
        $migrator = new SpecMigrator(
            Paths::fromCwd($this->project->root),
            new ManifestVersionResolver(),
            [],
        );

        $bag = new DiagnosticBag();
        $missing = $migrator->migrate(false, 'app/features/missing/feature.yaml', $bag);
        $this->assertNotEmpty($missing->diagnostics);
        $this->assertSame('FDY7004_NO_MIGRATION_PATH', $missing->diagnostics[0]['code']);
        $this->assertTrue($bag->hasErrors());

        $feature = $this->project->root . '/app/features/broken';
        mkdir($feature, 0777, true);
        file_put_contents($feature . '/feature.yaml', "version: [\nfeature: broken\n");

        $parsed = $migrator->migrate(false, 'app/features/broken/feature.yaml');
        $this->assertSame('FDY3002_MANIFEST_PARSE_FAILED', $parsed->diagnostics[0]['code']);
    }

    public function test_migrate_reports_unsupported_version_and_missing_migration_path(): void
    {
        $unsupportedDir = $this->project->root . '/app/features/unsupported';
        mkdir($unsupportedDir, 0777, true);
        file_put_contents($unsupportedDir . '/feature.yaml', "version: 9\nfeature: unsupported\nkind: http\n");

        $missingPathDir = $this->project->root . '/app/features/missing_path';
        mkdir($missingPathDir, 0777, true);
        file_put_contents($missingPathDir . '/feature.yaml', "version: 1\nfeature: missing_path\nkind: http\n");

        $rule = new class implements MigrationRule {
            public function id(): string { return 'MIGRATE_2_TO_3'; }
            public function description(): string { return '2->3'; }
            public function sourceType(): string { return 'feature_manifest'; }
            public function fromVersion(): int { return 2; }
            public function toVersion(): int { return 3; }
            public function applies(string $path, array $document): bool { return true; }
            public function migrate(string $path, array $document): array { $document['version'] = 3; return $document; }
        };

        $resolver = new ManifestVersionResolver(3);
        $migrator = new SpecMigrator(
            Paths::fromCwd($this->project->root),
            $resolver,
            [$rule],
            [new SpecFormat('feature_manifest', 'Feature', 3, [1, 2, 3])],
        );

        $unsupported = $migrator->migrate(false, 'app/features/unsupported/feature.yaml');
        $this->assertSame('FDY7003_UNSUPPORTED_SPEC_VERSION', $unsupported->diagnostics[0]['code']);

        $missingPath = $migrator->migrate(false, 'app/features/missing_path/feature.yaml');
        $codes = array_values(array_map(static fn (array $row): string => (string) ($row['code'] ?? ''), $missingPath->diagnostics));
        $this->assertContains('FDY7004_NO_MIGRATION_PATH', $codes);
        $this->assertSame('missing_path', $missingPath->plans[0]['status']);
    }

    public function test_spec_format_listing_uses_provided_format_rows(): void
    {
        $migrator = new SpecMigrator(
            Paths::fromCwd($this->project->root),
            new ManifestVersionResolver(2),
            [],
            [
                new SpecFormat('z_format', 'Z', 1, [1]),
                new SpecFormat('a_format', 'A', 2, [1, 2]),
            ],
        );

        $formats = $migrator->specFormats();
        $this->assertSame('a_format', $formats[0]['name']);
        $this->assertSame('z_format', $formats[1]['name']);
    }
}
