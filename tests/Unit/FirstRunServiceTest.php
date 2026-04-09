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

    private function makeTempDirectory(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertIsString($path);
        @unlink($path);
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
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
