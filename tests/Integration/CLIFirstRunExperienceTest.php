<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIFirstRunExperienceTest extends TestCase
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

    public function test_no_args_in_empty_directory_loads_the_recommended_example_and_runs_the_walkthrough(): void
    {
        $root = $this->makeTempDirectory('foundry-first-run-empty-');
        chdir($root);

        $result = $this->runCommand(new Application(), ['foundry', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('example', $result['payload']['mode']);
        $this->assertSame('blog-api', $result['payload']['example']['name']);
        $this->assertSame('canonical', $result['payload']['example']['taxonomy']);
        $this->assertSame('direct_copy', $result['payload']['example']['mode']);
        $this->assertSame(['blog-api'], $result['payload']['example']['source_examples']);
        $this->assertSame('feature:list_posts', $result['payload']['example']['explain_default_target']);
        $this->assertSame('working_directory', $result['payload']['workspace_mode']);
        $this->assertSame($root, $result['payload']['target_path']);
        $this->assertContains('list_posts', $result['payload']['graph']['summary']['features']);
        $this->assertSame('feature', $result['payload']['explain']['subject']['kind']);
        $this->assertFileExists($root . '/app/features/list_posts/feature.yaml');
        $this->assertFileExists($root . '/README.md');

        $this->deleteDirectory($root);
    }

    public function test_init_example_supports_temp_mode_for_non_empty_directories(): void
    {
        $root = $this->makeTempDirectory('foundry-first-run-init-');
        file_put_contents($root . '/notes.txt', "keep me\n");
        chdir($root);

        $result = $this->runCommand(new Application(), ['foundry', 'init', '--example=blog-api', '--temp', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('example', $result['payload']['mode']);
        $this->assertSame('temp_directory', $result['payload']['workspace_mode']);
        $this->assertNotSame($root, $result['payload']['target_path']);
        $this->assertFileExists($root . '/notes.txt');
        $this->assertFileExists($result['payload']['target_path'] . '/app/features/list_posts/feature.yaml');

        $this->deleteDirectory((string) $result['payload']['target_path']);
        $this->deleteDirectory($root);
    }

    public function test_examples_commands_list_and_load_curated_examples(): void
    {
        $root = $this->makeTempDirectory('foundry-first-run-examples-');
        chdir($root);

        $list = $this->runCommand(new Application(), ['foundry', 'examples:list', '--json']);
        $this->assertSame(0, $list['status']);
        $exampleNames = array_map(
            static fn(array $row): string => (string) ($row['name'] ?? ''),
            $list['payload']['examples'],
        );
        $this->assertSame(['blog-api', 'extensions-migrations'], $exampleNames);
        $this->assertSame('canonical', $list['payload']['examples'][0]['taxonomy']);
        $this->assertSame('direct_copy', $list['payload']['examples'][0]['mode']);
        $this->assertSame(['blog-api'], $list['payload']['examples'][0]['source_examples']);
        $this->assertSame('reference', $list['payload']['examples'][1]['taxonomy']);
        $this->assertSame('composed', $list['payload']['examples'][1]['mode']);
        $this->assertSame(['hello-world', 'extensions-migrations'], $list['payload']['examples'][1]['source_examples']);

        file_put_contents($root . '/scratch.txt', "busy\n");
        $load = $this->runCommand(new Application(), ['foundry', 'examples:load', 'extensions-migrations', '--temp', '--json']);
        $this->assertSame(0, $load['status']);
        $this->assertSame('extensions-migrations', $load['payload']['example']['name']);
        $this->assertSame('reference', $load['payload']['example']['taxonomy']);
        $this->assertSame('composed', $load['payload']['example']['mode']);
        $this->assertFileExists($load['payload']['target_path'] . '/foundry.extensions.php');
        $this->assertFileExists($load['payload']['target_path'] . '/app/features/say_hello/feature.yaml');

        $this->deleteDirectory((string) $load['payload']['target_path']);
        $this->deleteDirectory($root);
    }

    public function test_examples_list_text_output_describes_taxonomy_mode_and_sources_truthfully(): void
    {
        $root = $this->makeTempDirectory('foundry-first-run-list-text-');
        chdir($root);

        $result = $this->runCommandRaw(new Application(), ['foundry', 'examples:list']);

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('blog-api [canonical / direct_copy]', $result['output']);
        $this->assertStringContainsString('Sources: blog-api', $result['output']);
        $this->assertStringContainsString('extensions-migrations [reference / composed]', $result['output']);
        $this->assertStringContainsString('Sources: hello-world, extensions-migrations', $result['output']);

        $this->deleteDirectory($root);
    }

    public function test_no_args_in_existing_project_runs_explain_driven_orientation(): void
    {
        $project = new TempProject();
        $repoRoot = dirname(__DIR__, 2);

        try {
            $this->copyDirectory(
                $repoRoot . '/examples/hello-world/app',
                $project->root . '/app',
            );
            chdir($project->root);

            $result = $this->runCommand(new Application(), ['foundry', '--json']);

            $this->assertSame(0, $result['status']);
            $this->assertSame('existing_project', $result['payload']['mode']);
            $this->assertTrue($result['payload']['project_detected']);
            $this->assertContains('say_hello', $result['payload']['graph']['summary']['features']);
            $this->assertSame('feature:say_hello', $result['payload']['explain']['subject']['id']);
        } finally {
            $project->cleanup();
        }
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = trim((string) ob_get_clean());

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,output:string}
     */
    private function runCommandRaw(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = trim((string) ob_get_clean());

        return ['status' => $status, 'output' => $output];
    }

    private function makeTempDirectory(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertIsString($path);
        @unlink($path);
        mkdir($path, 0777, true);

        return str_replace('\\', '/', $path);
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
