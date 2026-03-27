<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Quality\QualityToolRunner;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class QualityToolRunnerTest extends TestCase
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

    public function test_parses_static_and_style_tool_output(): void
    {
        $this->writeExecutable($this->project->root . '/vendor/bin/phpstan', <<<'SH'
#!/bin/sh
cat <<'JSON'
{"totals":{"errors":0,"file_errors":1},"files":{"app/features/publish_post/action.php":{"errors":1,"messages":[{"message":"Undefined method call.","line":12,"identifier":"method.notFound","tip":"Fix the receiver type."}]}}}
JSON
exit 1
SH);
        $this->writeExecutable($this->project->root . '/vendor/bin/pint', <<<'SH'
#!/bin/sh
cat <<'JSON'
{"files":["app/features/publish_post/action.php"]}
JSON
exit 1
SH);

        $runner = new QualityToolRunner(new Paths($this->project->root, $this->project->root));
        $static = $runner->runStaticAnalysis();
        $style = $runner->runStyleCheck();

        $this->assertFalse($static['ok']);
        $this->assertSame(1, $static['summary']['total']);
        $this->assertSame('app/features/publish_post/action.php', $static['issues'][0]['path']);
        $this->assertSame('method.notFound', $static['issues'][0]['identifier']);

        $this->assertFalse($style['ok']);
        $this->assertSame(1, $style['summary']['total']);
        $this->assertSame('app/features/publish_post/action.php', $style['issues'][0]['path']);
    }

    public function test_reports_missing_tools(): void
    {
        $runner = new QualityToolRunner(new Paths($this->project->root, $this->project->root));

        $static = $runner->runStaticAnalysis();
        $style = $runner->runStyleCheck();

        $this->assertFalse($static['available']);
        $this->assertFalse($style['available']);
        $this->assertSame('error', $static['status']);
        $this->assertSame('error', $style['status']);
    }

    private function writeExecutable(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
        chmod($path, 0755);
    }
}
