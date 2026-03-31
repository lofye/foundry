<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Examples\ExampleLoader;
use Foundry\Support\Paths;
use PHPUnit\Framework\TestCase;

final class ExampleLoaderTest extends TestCase
{
    public function test_available_examples_expose_truthful_onboarding_metadata(): void
    {
        $loader = new ExampleLoader(Paths::fromCwd(dirname(__DIR__, 2)));

        $examples = $loader->available();

        $this->assertCount(2, $examples);

        $blog = $examples[0];
        $this->assertSame('blog-api', $blog['name']);
        $this->assertSame('canonical', $blog['taxonomy']);
        $this->assertSame('direct_copy', $blog['mode']);
        $this->assertSame(['blog-api'], $blog['source_examples']);
        $this->assertSame(['examples/blog-api'], $blog['source_paths']);
        $this->assertSame('feature:list_posts', $blog['explain_default_target']);
        $this->assertTrue($blog['recommended']);

        $extensions = $examples[1];
        $this->assertSame('extensions-migrations', $extensions['name']);
        $this->assertSame('reference', $extensions['taxonomy']);
        $this->assertSame('composed', $extensions['mode']);
        $this->assertSame(['hello-world', 'extensions-migrations'], $extensions['source_examples']);
        $this->assertSame(['examples/hello-world/app', 'examples/extensions-migrations'], $extensions['source_paths']);
        $this->assertSame('feature:say_hello', $extensions['explain_default_target']);
        $this->assertFalse($extensions['recommended']);
    }

    public function test_blog_alias_resolves_to_the_blog_api_example(): void
    {
        $loader = new ExampleLoader(Paths::fromCwd(dirname(__DIR__, 2)));
        $target = $this->makeTempDirectory('foundry-example-loader-');

        try {
            $result = $loader->load('blog', $target);

            $this->assertSame('blog-api', $result['example']['name']);
            $this->assertSame('working_directory', $result['workspace_mode']);
            $this->assertFileExists($target . '/app/features/list_posts/feature.yaml');
        } finally {
            $this->deleteDirectory($target);
        }
    }

    private function makeTempDirectory(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        self::assertIsString($path);
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
