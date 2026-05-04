<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Quality\ImplementationQualityGateService;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ImplementationQualityGateServiceTest extends TestCase
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

    public function test_quality_gate_passes_when_full_suite_and_coverage_meet_threshold(): void
    {
        $result = $this->service()->verify([]);

        $this->assertTrue($result['passed']);
        $this->assertSame('passed', $result['status']);
        $this->assertTrue($result['full_suite']['ran']);
        $this->assertTrue($result['full_suite']['passed']);
        $this->assertTrue($result['coverage']['ran']);
        $this->assertTrue($result['coverage']['passed']);
        $this->assertSame(95.0, $result['coverage']['global_line_coverage']);
        $this->assertTrue($result['changed_surface']['supported']);
        $this->assertSame('no_enforced_files', $result['changed_surface']['status']);
        $this->assertTrue($result['changed_surface']['passed']);
        $this->assertFileDoesNotExist($this->project->root . '/build/coverage/clover.xml');
    }

    public function test_quality_gate_fails_when_full_suite_fails(): void
    {
        file_put_contents($this->project->root . '/.foundry-test-phpunit-exit-code', "1\n");

        $result = $this->service()->verify([]);

        $this->assertFalse($result['passed']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('IMPLEMENTATION_QUALITY_GATE_FULL_SUITE_FAILED', $result['issues'][0]['code']);
        $this->assertFalse($result['coverage']['ran']);
    }

    public function test_quality_gate_fails_when_coverage_run_fails(): void
    {
        file_put_contents($this->project->root . '/.foundry-test-coverage-exit-code', "1\n");

        $result = $this->service()->verify([]);

        $this->assertFalse($result['passed']);
        $this->assertSame('IMPLEMENTATION_QUALITY_GATE_COVERAGE_FAILED', $result['issues'][0]['code']);
        $this->assertTrue($result['full_suite']['passed']);
        $this->assertTrue($result['coverage']['ran']);
        $this->assertFalse($result['coverage']['passed']);
        $this->assertFileDoesNotExist($this->project->root . '/build/coverage/clover.xml');
    }

    public function test_quality_gate_fails_when_global_coverage_is_below_threshold(): void
    {
        $this->writeCoverageFile('src/Foo.php', 10, 8);

        $result = $this->service()->verify([]);

        $this->assertFalse($result['passed']);
        $this->assertSame('IMPLEMENTATION_QUALITY_GATE_GLOBAL_COVERAGE_BELOW_THRESHOLD', $result['issues'][0]['code']);
        $this->assertSame(80.0, $result['coverage']['global_line_coverage']);
        $this->assertFalse($result['coverage']['meets_threshold']);
    }

    public function test_quality_gate_fails_when_clover_metrics_are_unparseable(): void
    {
        file_put_contents($this->project->root . '/.foundry-test-skip-coverage-clover', "1\n");

        $result = $this->service()->verify([]);

        $this->assertFalse($result['passed']);
        $this->assertSame('IMPLEMENTATION_QUALITY_GATE_COVERAGE_UNPARSEABLE', $result['issues'][0]['code']);
        $this->assertNull($result['coverage']['global_line_coverage']);
        $this->assertNull($result['coverage']['meets_threshold']);
    }

    public function test_quality_gate_fails_when_changed_files_cannot_be_determined_without_repository_context(): void
    {
        $result = $this->service()->verify();

        $this->assertFalse($result['passed']);
        $this->assertSame('IMPLEMENTATION_QUALITY_GATE_CHANGED_SURFACE_UNDETERMINED', $result['issues'][0]['code']);
        $this->assertSame('unresolved', $result['changed_surface']['status']);
        $this->assertSame([], $result['changed_surface']['changed_files']);
    }

    public function test_quality_gate_passes_when_changed_php_source_files_meet_threshold(): void
    {
        $this->writeCoverageFile('src/Foo.php', 10, 9);

        $result = $this->service()->verify(['src/Foo.php']);

        $this->assertTrue($result['passed']);
        $this->assertSame('passed', $result['changed_surface']['status']);
        $this->assertSame(['src/Foo.php'], $result['changed_surface']['examined_files']);
        $this->assertSame(90.0, $result['changed_surface']['file_coverages'][0]['line_coverage']);
    }

    public function test_quality_gate_fails_when_changed_php_source_file_is_below_threshold(): void
    {
        $this->writeCoverageFiles([
            ['path' => 'src/Foo.php', 'statements' => 10, 'covered_statements' => 8],
            ['path' => 'src/Support.php', 'statements' => 90, 'covered_statements' => 90],
        ]);

        $result = $this->service()->verify(['src/Foo.php']);

        $this->assertFalse($result['passed']);
        $this->assertSame('IMPLEMENTATION_QUALITY_GATE_CHANGED_SURFACE_BELOW_THRESHOLD', $result['issues'][0]['code']);
        $this->assertSame('failed', $result['changed_surface']['status']);
        $this->assertSame('src/Foo.php', $result['changed_surface']['under_covered'][0]['path']);
        $this->assertSame(80.0, $result['changed_surface']['under_covered'][0]['line_coverage']);
    }

    public function test_quality_gate_includes_newly_added_source_files_in_changed_surface_enforcement(): void
    {
        $this->writeCoverageFile('src/NewFile.php', 12, 11);

        $result = $this->service()->verify(['src/NewFile.php']);

        $this->assertTrue($result['passed']);
        $this->assertSame(['src/NewFile.php'], $result['changed_surface']['changed_files']);
        $this->assertSame(['src/NewFile.php'], $result['changed_surface']['examined_files']);
    }

    public function test_quality_gate_ignores_untouched_and_non_enforced_files(): void
    {
        $this->writeCoverageFile('src/Foo.php', 10, 10);

        $result = $this->service()->verify([
            'docs/readme.md',
            'tests/Unit/FooTest.php',
            'src/Foo.php',
        ]);

        $this->assertTrue($result['passed']);
        $this->assertSame(
            ['docs/readme.md', 'src/Foo.php', 'tests/Unit/FooTest.php'],
            $result['changed_surface']['changed_files'],
        );
        $this->assertSame(['src/Foo.php'], $result['changed_surface']['examined_files']);
    }

    public function test_quality_gate_fails_when_changed_surface_attribution_is_missing(): void
    {
        $result = $this->service()->verify(['src/Foo.php']);

        $this->assertFalse($result['passed']);
        $this->assertSame('IMPLEMENTATION_QUALITY_GATE_CHANGED_SURFACE_ATTRIBUTION_FAILED', $result['issues'][0]['code']);
        $this->assertSame('unresolved', $result['changed_surface']['status']);
        $this->assertSame(['src/Foo.php'], $result['issues'][0]['missing_coverage_files']);
    }

    public function test_quality_gate_fails_when_clover_report_is_unreadable(): void
    {
        file_put_contents($this->project->root . '/.foundry-test-skip-coverage-clover', "1\n");

        $result = $this->service()->verify(['src/Foo.php']);

        $this->assertFalse($result['passed']);
        $this->assertSame('IMPLEMENTATION_QUALITY_GATE_COVERAGE_UNPARSEABLE', $result['issues'][0]['code']);
        $this->assertSame('not_run', $result['changed_surface']['status']);
        $this->assertSame([], $result['changed_surface']['examined_files']);
    }

    public function test_quality_gate_treats_zero_statement_changed_files_as_fully_covered(): void
    {
        $this->writeCoverageFile('src/EmptyFeature.php', 0, 0);

        $result = $this->service()->verify(['src/EmptyFeature.php']);

        $this->assertTrue($result['passed']);
        $this->assertSame('passed', $result['changed_surface']['status']);
        $this->assertSame(100.0, $result['changed_surface']['coverage']);
        $this->assertSame(100.0, $result['changed_surface']['file_coverages'][0]['line_coverage']);
    }

    public function test_quality_gate_excludes_generated_vendor_and_test_php_files_from_changed_surface_enforcement(): void
    {
        $this->writeCoverageFile('src/Foo.php', 10, 10);

        $result = $this->service()->verify([
            '.foundry/hooks.php',
            'docs/guide.php',
            'vendor/package/Foo.php',
            'storage/cache/Foo.php',
            'app/generated/Foo.php',
            'stubs/example.php',
            'src/tests/FooTest.php',
        ]);

        $this->assertTrue($result['passed']);
        $this->assertSame('no_enforced_files', $result['changed_surface']['status']);
        $this->assertSame([], $result['changed_surface']['examined_files']);
    }

    public function test_quality_gate_normalizes_changed_file_paths_before_enforcement(): void
    {
        $this->writeCoverageFile('src/Foo.php', 10, 9);

        $result = $this->service()->verify([
            ' src\\Foo.php ',
            'src/Foo.php',
        ]);

        $this->assertTrue($result['passed']);
        $this->assertSame(['src/Foo.php'], $result['changed_surface']['changed_files']);
        $this->assertSame(['src/Foo.php'], $result['changed_surface']['examined_files']);
    }

    public function test_quality_gate_no_longer_accepts_unsupported_changed_surface_state(): void
    {
        $this->writeCoverageFile('src/Foo.php', 10, 9);

        $result = $this->service()->verify(['src/Foo.php']);

        $this->assertNotSame('not_supported', $result['changed_surface']['status']);
        $this->assertTrue($result['changed_surface']['supported']);
    }

    public function test_quality_gate_replaces_existing_clover_file_before_running_coverage(): void
    {
        $directory = $this->project->root . '/build/coverage';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory . '/clover.xml', 'stale-content');

        $result = $this->service()->verify([]);

        $this->assertTrue($result['passed']);
        $this->assertSame('no_enforced_files', $result['changed_surface']['status']);
        $this->assertFileDoesNotExist($this->project->root . '/build/coverage/clover.xml');
    }

    private function service(): ImplementationQualityGateService
    {
        return new ImplementationQualityGateService(new Paths($this->project->root));
    }

    private function writeCoverageFile(string $relativePath, int $statements, int $coveredStatements): void
    {
        $this->writeCoverageFiles([
            [
                'path' => $relativePath,
                'statements' => $statements,
                'covered_statements' => $coveredStatements,
            ],
        ]);
    }

    /**
     * @param list<array{path:string,statements:int,covered_statements:int}> $rows
     */
    private function writeCoverageFiles(array $rows): void
    {
        $payload = [];

        foreach ($rows as $row) {
            $absolutePath = $this->project->root . '/' . $row['path'];
            $directory = dirname($absolutePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($absolutePath, "<?php\n");
            $payload[] = [
                'path' => $absolutePath,
                'statements' => $row['statements'],
                'covered_statements' => $row['covered_statements'],
            ];
        }

        file_put_contents(
            $this->project->root . '/.foundry-test-coverage-files.json',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
