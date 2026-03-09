<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Codemod\PhaseOneSpecNormalizeCodemod;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class PhaseOneSpecNormalizeCodemodTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        mkdir($this->project->root . '/app/specs/resources', 0777, true);
        file_put_contents($this->project->root . '/app/specs/resources/posts.resource.yaml', <<<'YAML'
resource: posts
fields:
  title:
    required: true
    type: string
version: 1
YAML);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_codemod_reports_changes_in_dry_run_and_write_modes(): void
    {
        file_put_contents($this->project->root . '/app/specs/resources/posts.resource.yaml', <<<'YAML'
resource: posts
fields:
  title:
    required: true
    type: string
YAML);

        $codemod = new PhaseOneSpecNormalizeCodemod();
        $dryRun = $codemod->run(Paths::fromCwd($this->project->root), false);
        $this->assertNotEmpty($dryRun->changes);
        $this->assertFalse($dryRun->written);

        $write = $codemod->run(Paths::fromCwd($this->project->root), true);
        $this->assertNotEmpty($write->changes);
        $this->assertTrue($write->written);

        $content = (string) file_get_contents($this->project->root . '/app/specs/resources/posts.resource.yaml');
        $this->assertStringContainsString('version: 1', $content);
    }

    public function test_codemod_reports_parse_diagnostics_for_invalid_spec_files(): void
    {
        file_put_contents($this->project->root . '/app/specs/resources/posts.resource.yaml', "version: [\nresource: posts\n");

        $codemod = new PhaseOneSpecNormalizeCodemod();
        $result = $codemod->run(Paths::fromCwd($this->project->root), false);

        $this->assertNotEmpty($result->diagnostics);
        $this->assertSame('FDY2213_PHASE1_SPEC_PARSE_ERROR', $result->diagnostics[0]['code']);
    }
}
