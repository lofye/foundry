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

    public function test_run_tests_reports_missing_passing_and_failing_phpunit_states(): void
    {
        $runner = new QualityToolRunner(new Paths($this->project->root, $this->project->root));
        chmod($this->project->root . '/vendor/bin/phpunit', 0755);

        $passed = $runner->runTests();
        $this->assertTrue($passed['available']);
        $this->assertTrue($passed['ok']);
        $this->assertSame('passed', $passed['status']);

        file_put_contents($this->project->root . '/.foundry-test-phpunit-exit-code', '1');

        $failed = $runner->runTests();
        $this->assertTrue($failed['available']);
        $this->assertFalse($failed['ok']);
        $this->assertSame('error', $failed['status']);

        @unlink($this->project->root . '/vendor/bin/phpunit');

        $missing = $runner->runTests();
        $this->assertFalse($missing['available']);
        $this->assertSame('missing', $missing['status']);
    }

    public function test_parses_trailing_phpstan_json_and_plain_text_fallbacks(): void
    {
        $this->writeExecutable($this->project->root . '/vendor/bin/phpstan', <<<'SH'
#!/bin/sh
cat <<'OUT'
Debug prelude
{"totals":{"errors":1,"file_errors":1},"files":{"app/features/demo/action.php":{"messages":[{"message":"Undefined variable.","line":9,"identifier":"var.undefined","tip":"Initialize it."}]}}}
OUT
exit 1
SH);

        $runner = new QualityToolRunner(new Paths($this->project->root, $this->project->root));
        $result = $runner->runStaticAnalysis();

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['summary']['total']);
        $this->assertSame('app/features/demo/action.php', $result['issues'][0]['path']);
        $this->assertSame('var.undefined', $result['issues'][0]['identifier']);

        $this->writeExecutable($this->project->root . '/vendor/bin/phpstan', <<<'SH'
#!/bin/sh
echo "Unparseable but successful"
exit 0
SH);

        $passed = $runner->runStaticAnalysis();
        $this->assertTrue($passed['ok']);
        $this->assertSame('passed', $passed['status']);
        $this->assertSame([], $passed['issues']);
    }

    public function test_style_check_parses_multiple_json_shapes_and_plain_text_output(): void
    {
        $this->writeExecutable($this->project->root . '/vendor/bin/pint', <<<'SH'
#!/bin/sh
cat <<'JSON'
{"files":{"app/features/demo/action.php":{"name":"app/features/demo/action.php"},"app/features/demo/other.php":[]}}
JSON
exit 1
SH);

        $runner = new QualityToolRunner(new Paths($this->project->root, $this->project->root));
        $jsonResult = $runner->runStyleCheck();

        $this->assertFalse($jsonResult['ok']);
        $this->assertSame(2, $jsonResult['summary']['total']);
        $this->assertSame('app/features/demo/action.php', $jsonResult['issues'][0]['path']);
        $this->assertSame('app/features/demo/other.php', $jsonResult['issues'][1]['path']);

        $this->writeExecutable($this->project->root . '/vendor/bin/pint', <<<'SH'
#!/bin/sh
printf 'Style line one\n\nStyle line two\n'
exit 1
SH);

        $textResult = $runner->runStyleCheck();
        $this->assertFalse($textResult['ok']);
        $this->assertSame(2, $textResult['summary']['total']);
        $this->assertSame('Style line one', $textResult['issues'][0]['message']);
        $this->assertSame('Style line two', $textResult['issues'][1]['message']);
    }

    private function writeExecutable(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
        chmod($path, 0755);
    }
}
