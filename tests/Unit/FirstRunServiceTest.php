<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\CLI\CommandContext;
use Foundry\UX\FirstRunService;
use PHPUnit\Framework\TestCase;

final class FirstRunServiceTest extends TestCase
{
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = getcwd() ?: '.';
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
    }

    public function test_json_first_run_remains_non_interactive_even_when_terminal_is_interactive(): void
    {
        $root = $this->makeTempDirectory('foundry-first-run-json-');
        $service = new FirstRunService(
            interactive: true,
            inputReader: static function (string $prompt): never {
                throw new \RuntimeException('Unexpected interactive prompt: ' . $prompt);
            },
        );

        $result = $service->run(new CommandContext(cwd: $root, jsonOutput: true));

        $this->assertSame(0, $result['status']);
        $this->assertNull($result['message']);
        $this->assertIsArray($result['payload']);
        $this->assertSame('example', $result['payload']['mode']);
        $this->assertSame('blog-api', $result['payload']['example']['name']);
        $this->assertFileExists($root . '/app/features/list_posts/feature.yaml');
        $this->assertFileExists($root . '/README.md');

        $this->deleteDirectory($root);
    }

    public function test_interactive_first_run_can_exit_cleanly(): void
    {
        $root = $this->makeTempDirectory('foundry-first-run-exit-');
        $service = new FirstRunService(
            interactive: true,
            inputReader: static fn(string $prompt): string => '3',
        );
        $this->expectOutputRegex('/Foundry Framework[\s\S]*Choose an option:/');

        $result = $service->run(new CommandContext(cwd: $root));

        $this->assertSame(0, $result['status']);
        $this->assertNull($result['payload']);
        $this->assertSame('First-run setup skipped.', $result['message']);

        $this->deleteDirectory($root);
    }

    public function test_interactive_first_run_can_report_no_project_in_human_mode(): void
    {
        $root = $this->makeTempDirectory('foundry-first-run-no-project-');
        $service = new FirstRunService(
            interactive: true,
            inputReader: static fn(string $prompt): string => '2',
        );
        $this->expectOutputRegex('/Foundry Framework[\s\S]*Choose an option:/');

        $result = $service->run(new CommandContext(cwd: $root));

        $this->assertSame(0, $result['status']);
        $this->assertNull($result['payload']);
        $this->assertStringContainsString('No Foundry project is active in this directory yet.', (string) $result['message']);
        $this->assertStringContainsString('foundry init --example=blog-api', (string) $result['message']);

        $this->deleteDirectory($root);
    }

    public function test_non_json_existing_project_flow_renders_orientation_message(): void
    {
        $root = $this->makeTempDirectory('foundry-first-run-existing-');
        $repoRoot = dirname(__DIR__, 2);
        $this->copyDirectory($repoRoot . '/examples/hello-world/app', $root . '/app');

        $service = new FirstRunService(interactive: false);
        $result = $service->run(new CommandContext(cwd: $root));

        $this->assertSame(0, $result['status']);
        $this->assertNull($result['payload']);
        $this->assertStringContainsString('This project is ready.', (string) $result['message']);
        $this->assertStringContainsString('foundry doctor', (string) $result['message']);

        $this->deleteDirectory($root);
    }

    public function test_invalid_interactive_selection_falls_back_to_recommended_example(): void
    {
        $root = $this->makeTempDirectory('foundry-first-run-fallback-example-');
        $inputs = ['wat', 'wat'];
        $service = new FirstRunService(
            interactive: true,
            inputReader: static function (string $prompt) use (&$inputs): string {
                return array_shift($inputs) ?? 'wat';
            },
        );
        $this->expectOutputRegex('/Foundry Framework[\s\S]*Choose an option:[\s\S]*Select an example:/');

        $result = $service->run(new CommandContext(cwd: $root));

        $this->assertSame(0, $result['status']);
        $this->assertNull($result['payload']);
        $this->assertStringContainsString('Loaded example:', (string) $result['message']);
        $this->assertFileExists($root . '/app/features/list_posts/feature.yaml');

        $this->deleteDirectory($root);
    }

    private function makeTempDirectory(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertIsString($path);
        if (file_exists($path)) {
            unlink($path);
        }
        mkdir($path, 0777, true);

        return str_replace('\\', '/', $path);
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            self::fail('Missing source directory: ' . $source);
        }

        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0777, true);
                }

                continue;
            }

            $directory = dirname($destination);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            copy($item->getPathname(), $destination);
        }
    }
}
