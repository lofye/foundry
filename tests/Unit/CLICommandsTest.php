<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\QueueWorkCommand;
use Foundry\CLI\Commands\ScheduleRunCommand;
use Foundry\CLI\Commands\ServeCommand;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLICommandsTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);

        file_put_contents($this->project->root . '/app/generated/job_index.php', '<?php return ["notify" => ["queue" => "default"]];');
        file_put_contents($this->project->root . '/app/generated/scheduler_index.php', '<?php return ["task" => ["frequency" => "always"]];');
        file_put_contents($this->project->root . '/storage/logs/trace.log', "line1\nline2\n");
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_serve_command_returns_hint(): void
    {
        $ctx = new CommandContext();
        $result = (new ServeCommand())->run(['serve'], $ctx);

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('php -S', (string) $result['payload']['hint']);
    }

    public function test_queue_inspect_command_returns_jobs(): void
    {
        $ctx = new CommandContext();
        $result = (new QueueWorkCommand())->run(['queue:inspect'], $ctx);

        $this->assertSame(0, $result['status']);
        $this->assertSame(1, $result['payload']['count']);
    }

    public function test_schedule_and_trace_commands_return_payloads(): void
    {
        $ctx = new CommandContext();

        $schedule = (new ScheduleRunCommand())->run(['schedule:run'], $ctx);
        $this->assertSame(0, $schedule['status']);

        $trace = (new ScheduleRunCommand())->run(['trace:tail'], $ctx);
        $this->assertSame(0, $trace['status']);
        $this->assertCount(2, $trace['payload']['events']);
    }
}
