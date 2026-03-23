<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class LocalFoundryExecutableTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_root_foundry_launcher_passes_arguments_through_to_vendor_binary(): void
    {
        $target = $this->scaffoldApp('local-launcher');
        $this->writeVendorBinary($target, <<<'PHP'
<?php
declare(strict_types=1);

echo json_encode([
    'argv' => $_SERVER['argv'] ?? [],
], JSON_THROW_ON_ERROR);
PHP);

        $result = $this->runProcess([PHP_BINARY, $target . '/foundry', 'inspect', 'graph', '--json'], $target);

        $this->assertSame(0, $result['status']);
        $this->assertSame('', $result['stderr']);

        /** @var array<string,mixed> $payload */
        $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['inspect', 'graph', '--json'], array_slice((array) ($payload['argv'] ?? []), 1));
    }

    public function test_root_foundry_launcher_reports_missing_dependencies_cleanly(): void
    {
        $target = $this->scaffoldApp('missing-deps');

        $result = $this->runProcess([PHP_BINARY, $target . '/foundry', 'inspect', 'graph', '--json'], $target);

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('Missing vendor/bin/foundry', $result['stderr']);
        $this->assertStringContainsString('Run composer install first.', $result['stderr']);
    }

    public function test_vendor_bin_foundry_usage_remains_available(): void
    {
        $target = $this->scaffoldApp('vendor-bin-contract');
        $this->writeVendorBinary($target, <<<'PHP'
<?php
declare(strict_types=1);

echo json_encode([
    'argv' => $_SERVER['argv'] ?? [],
], JSON_THROW_ON_ERROR);
PHP);

        $result = $this->runProcess([PHP_BINARY, $target . '/vendor/bin/foundry', 'verify', 'graph', '--json'], $target);

        $this->assertSame(0, $result['status']);

        /** @var array<string,mixed> $payload */
        $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['verify', 'graph', '--json'], array_slice((array) ($payload['argv'] ?? []), 1));
    }

    private function scaffoldApp(string $name): string
    {
        $target = $this->project->root . '/' . $name;
        $result = $this->runCommand(new Application(), ['foundry', 'new', $target, '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertFileExists($target . '/foundry');

        return $target;
    }

    private function writeVendorBinary(string $target, string $body): void
    {
        @mkdir($target . '/vendor/bin', 0777, true);
        file_put_contents($target . '/vendor/bin/foundry', "#!/usr/bin/env php\n" . $body . "\n");
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = ob_get_clean() ?: '';

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }

    /**
     * @param array<int,string> $command
     * @return array{status:int,stdout:string,stderr:string}
     */
    private function runProcess(array $command, string $cwd): array
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $cwd,
        );

        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [
            'status' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
