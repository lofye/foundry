<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Quality\CloverCoverageVerifier;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CloverCoverageVerifierTest extends TestCase
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

    public function test_verify_passes_when_line_coverage_meets_threshold(): void
    {
        $this->writeClover(90, 100);

        $result = $this->verifier()->verify('build/coverage/clover.xml', 90.0);

        $this->assertSame('pass', $result['status']);
        $this->assertSame(90.0, $result['line_coverage_percent']);
        $this->assertSame(90, $result['covered_lines']);
        $this->assertSame(100, $result['total_lines']);
        $this->assertSame(90.0, $result['min_required']);
    }

    public function test_verify_fails_when_line_coverage_is_below_threshold(): void
    {
        $this->writeClover(89, 100);

        $result = $this->verifier()->verify('build/coverage/clover.xml', 90.0);

        $this->assertSame('fail', $result['status']);
        $this->assertSame(89.0, $result['line_coverage_percent']);
        $this->assertSame(89, $result['covered_lines']);
        $this->assertSame(100, $result['total_lines']);
    }

    public function test_verify_fails_when_clover_file_is_missing(): void
    {
        $result = $this->verifier()->verify('build/coverage/missing.xml', 90.0);

        $this->assertSame('fail', $result['status']);
        $this->assertSame(0.0, $result['line_coverage_percent']);
        $this->assertSame(0, $result['covered_lines']);
        $this->assertSame(0, $result['total_lines']);
    }

    public function test_verify_uses_default_path_when_clover_path_is_blank(): void
    {
        $this->writeClover(91, 100);

        $result = $this->verifier()->verify('   ', 90.0);

        $this->assertSame('pass', $result['status']);
        $this->assertSame('build/coverage/clover.xml', $result['clover_path']);
    }

    public function test_verify_normalizes_relative_path_prefixes(): void
    {
        $this->writeClover(91, 100);

        $result = $this->verifier()->verify('./build/coverage/clover.xml', 90.0);

        $this->assertSame('pass', $result['status']);
        $this->assertSame('build/coverage/clover.xml', $result['clover_path']);
    }

    public function test_summarize_returns_null_for_empty_xml(): void
    {
        $directory = $this->project->root . '/build/coverage';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/clover.xml', " \n\t ");

        $this->assertNull($this->verifier()->summarize('build/coverage/clover.xml'));
    }

    public function test_summarize_falls_back_to_global_metrics_when_file_metrics_are_missing(): void
    {
        $directory = $this->project->root . '/build/coverage';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/clover.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="0">
  <project timestamp="0">
    <file name="/tmp/app/src/Foo.php">
      <line num="10" type="stmt" count="1"/>
    </file>
    <metrics statements="80" coveredstatements="70"/>
    <metrics files="1" statements="100" coveredstatements="90"/>
  </project>
</coverage>
XML);

        $summary = $this->verifier()->summarize('build/coverage/clover.xml');

        $this->assertSame([
            'line_coverage_percent' => 90.0,
            'covered_lines' => 90,
            'total_lines' => 100,
        ], $summary);
    }

    public function test_summarize_prefers_metrics_with_files_attribute_and_larger_statement_count(): void
    {
        $directory = $this->project->root . '/build/coverage';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/clover.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="0">
  <project timestamp="0">
    <metrics statements="300" coveredstatements="285"/>
    <metrics files="2" statements="200" coveredstatements="180"/>
    <metrics files="3" statements="220" coveredstatements="198"/>
  </project>
</coverage>
XML);

        $summary = $this->verifier()->summarize('build/coverage/clover.xml');

        $this->assertSame(90.0, $summary['line_coverage_percent']);
        $this->assertSame(198, $summary['covered_lines']);
        $this->assertSame(220, $summary['total_lines']);
    }

    public function test_summarize_returns_null_when_no_statement_metrics_exist(): void
    {
        $directory = $this->project->root . '/build/coverage';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/clover.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="0">
  <project timestamp="0">
    <file name="/tmp/app/src/Foo.php">
      <metrics methods="2" coveredmethods="2"/>
    </file>
  </project>
</coverage>
XML);

        $this->assertNull($this->verifier()->summarize('build/coverage/clover.xml'));
    }

    public function test_summarize_treats_zero_total_statements_as_full_coverage(): void
    {
        $this->writeClover(0, 0);

        $summary = $this->verifier()->summarize('build/coverage/clover.xml');

        $this->assertSame(100.0, $summary['line_coverage_percent']);
        $this->assertSame(0, $summary['covered_lines']);
        $this->assertSame(0, $summary['total_lines']);
    }

    private function verifier(): CloverCoverageVerifier
    {
        return new CloverCoverageVerifier(new Paths($this->project->root));
    }

    private function writeClover(int $coveredStatements, int $statements): void
    {
        $directory = $this->project->root . '/build/coverage';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/clover.xml', sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<coverage generated=\"0\">\n  <project timestamp=\"0\">\n    <file name=\"%s/src/Foo.php\">\n      <metrics statements=\"%d\" coveredstatements=\"%d\"/>\n    </file>\n    <metrics files=\"1\" statements=\"%d\" coveredstatements=\"%d\"/>\n  </project>\n</coverage>\n",
            $this->project->root,
            $statements,
            $coveredStatements,
            $statements,
            $coveredStatements,
        ));
    }
}
