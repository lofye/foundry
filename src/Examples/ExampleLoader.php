<?php

declare(strict_types=1);

namespace Foundry\Examples;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class ExampleLoader
{
    public function __construct(private readonly ?Paths $paths = null) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function available(): array
    {
        return array_values(array_map(
            fn(array $definition): array => $this->publicDefinition($definition),
            $this->definitions(),
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function recommended(): array
    {
        foreach ($this->definitions() as $definition) {
            if ((bool) ($definition['recommended'] ?? false)) {
                return $this->publicDefinition($definition);
            }
        }

        $available = $this->available();
        if ($available !== []) {
            return $available[0];
        }

        throw new FoundryError(
            'EXAMPLE_NOT_FOUND',
            'not_found',
            ['example' => 'recommended'],
            'No onboarding examples are available.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function load(string $name, string $workingDirectory, bool $preferTemp = false): array
    {
        $definition = $this->definition($name);
        $workingDirectory = $this->normalizePath($workingDirectory);

        $targetPath = $preferTemp
            ? $this->createTempDirectory((string) $definition['name'])
            : $workingDirectory;

        if (!$preferTemp && !$this->isSafeWorkingDirectory($targetPath)) {
            throw new FoundryError(
                'EXAMPLE_TARGET_NOT_EMPTY',
                'validation',
                ['target_path' => $targetPath, 'example' => (string) $definition['name']],
                'The current directory is not empty. Use `foundry init --example=' . (string) $definition['name'] . ' --temp` to load into a temporary workspace.',
            );
        }

        $copied = [];
        foreach ((array) ($definition['copy_sets'] ?? []) as $copySet) {
            if (!is_array($copySet)) {
                continue;
            }

            $source = $this->normalizePath((string) ($copySet['source'] ?? ''));
            $destination = $this->destinationPath(
                $targetPath,
                (string) ($copySet['destination'] ?? ''),
            );

            foreach ($this->copyInto($source, $destination) as $path) {
                $copied[] = $path;
            }
        }

        $copied = array_values(array_unique(array_map($this->normalizePath(...), $copied)));
        sort($copied);

        return [
            'example' => $this->publicDefinition($definition),
            'target_path' => $targetPath,
            'workspace_mode' => $preferTemp ? 'temp_directory' : 'working_directory',
            'files_copied' => $copied,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function definitions(): array
    {
        $paths = $this->paths ?? Paths::fromCwd();
        $frameworkRoot = $paths->frameworkRoot();

        return [
            [
                'name' => 'blog-api',
                'aliases' => ['blog-api', 'blog'],
                'label' => 'Canonical Blog API example',
                'menu_label' => 'Load canonical Blog API example',
                'description' => 'Directly copy the canonical blog-api example into the current directory or a temporary workspace, then run explain.',
                'taxonomy' => 'canonical',
                'mode' => 'direct_copy',
                'source_examples' => ['blog-api'],
                'source_paths' => ['examples/blog-api'],
                'destination_behavior' => 'current_directory_or_temp_directory',
                'overwrite_behavior' => 'refuse_non_empty_without_temp',
                'explain_default_target' => 'feature:list_posts',
                'recommended' => true,
                'next_generate_intent' => 'Add comments to blog posts',
                'next_generate_mode' => 'modify',
                'next_generate_target' => 'list_posts',
                'copy_sets' => [
                    [
                        'source_example' => 'blog-api',
                        'source_path' => 'examples/blog-api',
                        'source' => $frameworkRoot . '/examples/blog-api',
                        'destination' => '',
                    ],
                ],
            ],
            [
                'name' => 'extensions-migrations',
                'aliases' => ['extensions-migrations', 'extensions', 'migrations'],
                'label' => 'Composed extensions and migrations reference setup',
                'menu_label' => 'Load composed reference setup for extensions and migrations',
                'description' => 'Compose the canonical hello-world app with the extensions-migrations reference assets, then run explain on the resulting workspace.',
                'taxonomy' => 'reference',
                'mode' => 'composed',
                'source_examples' => ['hello-world', 'extensions-migrations'],
                'source_paths' => ['examples/hello-world/app', 'examples/extensions-migrations'],
                'destination_behavior' => 'current_directory_or_temp_directory',
                'overwrite_behavior' => 'refuse_non_empty_without_temp',
                'explain_default_target' => 'feature:say_hello',
                'recommended' => false,
                'next_generate_intent' => 'Add a feature',
                'next_generate_mode' => 'new',
                'next_generate_target' => null,
                'copy_sets' => [
                    [
                        'source_example' => 'hello-world',
                        'source_path' => 'examples/hello-world/app',
                        'source' => $frameworkRoot . '/examples/hello-world/app',
                        'destination' => 'app',
                    ],
                    [
                        'source_example' => 'extensions-migrations',
                        'source_path' => 'examples/extensions-migrations/README.md',
                        'source' => $frameworkRoot . '/examples/extensions-migrations/README.md',
                        'destination' => 'README.md',
                    ],
                    [
                        'source_example' => 'extensions-migrations',
                        'source_path' => 'examples/extensions-migrations/foundry.extensions.php',
                        'source' => $frameworkRoot . '/examples/extensions-migrations/foundry.extensions.php',
                        'destination' => 'foundry.extensions.php',
                    ],
                    [
                        'source_example' => 'extensions-migrations',
                        'source_path' => 'examples/extensions-migrations/demo_extension',
                        'source' => $frameworkRoot . '/examples/extensions-migrations/demo_extension',
                        'destination' => 'demo_extension',
                    ],
                    [
                        'source_example' => 'extensions-migrations',
                        'source_path' => 'examples/extensions-migrations/migration',
                        'source' => $frameworkRoot . '/examples/extensions-migrations/migration',
                        'destination' => 'migration',
                    ],
                    [
                        'source_example' => 'extensions-migrations',
                        'source_path' => 'examples/extensions-migrations/codemod',
                        'source' => $frameworkRoot . '/examples/extensions-migrations/codemod',
                        'destination' => 'codemod',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function definition(string $name): array
    {
        $normalized = strtolower(trim($name));
        foreach ($this->definitions() as $definition) {
            $aliases = array_values(array_map(
                static fn(mixed $value): string => strtolower(trim((string) $value)),
                (array) ($definition['aliases'] ?? []),
            ));

            if (in_array($normalized, $aliases, true)) {
                return $definition;
            }
        }

        throw new FoundryError(
            'EXAMPLE_NOT_FOUND',
            'not_found',
            ['example' => $name],
            'Example not found.',
        );
    }

    /**
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    private function publicDefinition(array $definition): array
    {
        $copyPlan = array_values(array_map(
            static fn(array $copySet): array => [
                'source_example' => (string) ($copySet['source_example'] ?? ''),
                'source_path' => (string) ($copySet['source_path'] ?? ''),
                'destination' => (string) ($copySet['destination'] ?? ''),
            ],
            array_values(array_filter(
                (array) ($definition['copy_sets'] ?? []),
                static fn(mixed $copySet): bool => is_array($copySet),
            )),
        ));

        return [
            'name' => (string) ($definition['name'] ?? ''),
            'aliases' => array_values(array_map('strval', (array) ($definition['aliases'] ?? []))),
            'label' => (string) ($definition['label'] ?? ''),
            'menu_label' => (string) ($definition['menu_label'] ?? (string) ($definition['label'] ?? '')),
            'description' => (string) ($definition['description'] ?? ''),
            'taxonomy' => (string) ($definition['taxonomy'] ?? 'reference'),
            'mode' => (string) ($definition['mode'] ?? 'direct_copy'),
            'source_examples' => array_values(array_map('strval', (array) ($definition['source_examples'] ?? []))),
            'source_paths' => array_values(array_map('strval', (array) ($definition['source_paths'] ?? []))),
            'copy_plan' => $copyPlan,
            'destination_behavior' => (string) ($definition['destination_behavior'] ?? 'current_directory_or_temp_directory'),
            'overwrite_behavior' => (string) ($definition['overwrite_behavior'] ?? 'refuse_non_empty_without_temp'),
            'explain_default_target' => (string) ($definition['explain_default_target'] ?? ''),
            'recommended' => (bool) ($definition['recommended'] ?? false),
            'next_generate_intent' => (string) ($definition['next_generate_intent'] ?? ''),
            'next_generate_mode' => (string) ($definition['next_generate_mode'] ?? ''),
            'next_generate_target' => $definition['next_generate_target'] !== null
                ? (string) $definition['next_generate_target']
                : null,
        ];
    }

    private function destinationPath(string $targetRoot, string $destination): string
    {
        $destination = trim($destination);
        if ($destination === '') {
            return $targetRoot;
        }

        return $this->normalizePath($targetRoot . '/' . ltrim($destination, '/'));
    }

    /**
     * @return array<int,string>
     */
    private function copyInto(string $source, string $destination): array
    {
        if (is_file($source)) {
            $directory = dirname($destination);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            copy($source, $destination);

            return [$this->normalizePath($destination)];
        }

        if (!is_dir($source)) {
            throw new FoundryError(
                'EXAMPLE_SOURCE_MISSING',
                'runtime',
                ['source' => $source],
                'Example source is missing.',
            );
        }

        if (!is_dir($destination)) {
            mkdir($destination, 0777, true);
        }

        $copied = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $relativePath = substr($this->normalizePath($item->getPathname()), strlen(rtrim($source, '/') . '/'));
            $target = $this->normalizePath($destination . '/' . $relativePath);

            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0777, true);
                }

                continue;
            }

            $directory = dirname($target);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            copy($item->getPathname(), $target);
            $copied[] = $target;
        }

        return $copied;
    }

    private function isSafeWorkingDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);

            return true;
        }

        $items = scandir($path);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            return false;
        }

        return true;
    }

    private function createTempDirectory(string $name): string
    {
        $prefix = 'foundry-example-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)) . '-';
        $temporaryFile = tempnam(sys_get_temp_dir(), $prefix);
        if ($temporaryFile === false) {
            throw new FoundryError(
                'EXAMPLE_TEMP_DIRECTORY_FAILED',
                'runtime',
                ['example' => $name],
                'Unable to create a temporary directory for the example.',
            );
        }

        @unlink($temporaryFile);
        mkdir($temporaryFile, 0777, true);

        return $this->normalizePath($temporaryFile);
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, '/'));
    }
}
