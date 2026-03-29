<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Generation\ContextManifestGenerator;
use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ExamplesStructureTest extends TestCase
{
    public function test_catalog_entries_have_directories_readmes_and_metadata(): void
    {
        /** @var array<string,mixed> $catalog */
        $catalog = require getcwd() . '/examples/catalog.php';

        foreach ($this->catalogSections() as $section) {
            foreach ((array) ($catalog[$section] ?? []) as $example) {
                $this->assertIsArray($example);

                $path = getcwd() . '/' . (string) ($example['path'] ?? '');
                $this->assertDirectoryExists($path);
                $this->assertFileExists($path . '/README.md');
                $this->assertNotEmpty((array) ($example['teaches'] ?? []));
            }
        }
    }

    public function test_canonical_catalog_is_the_canonical_app_trio(): void
    {
        /** @var array<string,mixed> $catalog */
        $catalog = require getcwd() . '/examples/catalog.php';

        $canonicalSlugs = array_map(
            static fn(array $example): string => (string) ($example['slug'] ?? ''),
            (array) ($catalog['canonical'] ?? []),
        );

        $this->assertSame(['hello-world', 'blog-api', 'workflow-events'], $canonicalSlugs);
    }

    public function test_examples_docs_and_index_match_current_taxonomy(): void
    {
        /** @var array<string,mixed> $catalog */
        $catalog = require getcwd() . '/examples/catalog.php';
        $docs = file_get_contents(getcwd() . '/docs/example-applications.md') ?: '';
        $index = file_get_contents(getcwd() . '/examples/README.md') ?: '';

        foreach ($this->catalogSections() as $section) {
            foreach ((array) ($catalog[$section] ?? []) as $example) {
                $this->assertIsArray($example);

                $title = (string) ($example['title'] ?? '');
                $path = (string) ($example['path'] ?? '');

                $this->assertStringContainsString($title, $docs);
                $this->assertStringContainsString('../' . $path . '/README.md', $docs);
                $this->assertStringContainsString(substr($path, strlen('examples/')) . '/README.md', $index);
            }
        }

        $this->assertStringNotContainsString('Thresholds', $docs);
        $this->assertStringNotContainsString('Thresholds', $index);
        $this->assertStringNotContainsString('Dashboard', $docs);
        $this->assertStringNotContainsString('AI Pipeline', $docs);
        $this->assertStringContainsString('docs/example-applications.md', (string) file_get_contents(getcwd() . '/README.md'));
    }

    public function test_blog_api_example_contains_required_features(): void
    {
        $base = getcwd() . '/examples/blog-api/app/features';
        foreach (['list_posts', 'view_post', 'publish_post'] as $feature) {
            $this->assertDirectoryExists($base . '/' . $feature);
            $this->assertFileExists($base . '/' . $feature . '/feature.yaml');
            $this->assertFileExists($base . '/' . $feature . '/context.manifest.json');
        }
    }

    public function test_hello_world_example_contains_required_assets(): void
    {
        $base = getcwd() . '/examples/hello-world/app/features/say_hello';

        $this->assertDirectoryExists($base);
        $this->assertFileExists($base . '/feature.yaml');
        $this->assertFileExists($base . '/input.schema.json');
        $this->assertFileExists($base . '/output.schema.json');
        $this->assertFileExists($base . '/context.manifest.json');
        $this->assertFileExists($base . '/tests/say_hello_feature_test.php');
        $this->assertFileExists(getcwd() . '/examples/hello-world/README.md');
    }

    public function test_workflow_events_example_contains_required_features_and_workflow_definition(): void
    {
        $base = getcwd() . '/examples/workflow-events/app/features';
        foreach (['submit_story', 'review_story', 'publish_story'] as $feature) {
            $this->assertDirectoryExists($base . '/' . $feature);
            $this->assertFileExists($base . '/' . $feature . '/feature.yaml');
            $this->assertFileExists($base . '/' . $feature . '/events.yaml');
            $this->assertFileExists($base . '/' . $feature . '/context.manifest.json');
        }

        $this->assertFileExists(getcwd() . '/examples/workflow-events/app/definitions/workflows/editorial.workflow.yaml');
        $this->assertFileExists(getcwd() . '/examples/workflow-events/README.md');
    }

    public function test_reference_blog_kit_contains_commands_prompt_and_content(): void
    {
        $base = getcwd() . '/examples/reference-blog';

        $this->assertFileExists($base . '/README.md');
        $this->assertFileExists($base . '/commands.md');
        $this->assertFileExists($base . '/llm-prompt.md');
        $this->assertFileExists($base . '/content/about.md');
        $this->assertFileExists($base . '/content/welcome-post.md');
        $this->assertFileExists($base . '/content/editorial-notes.md');
    }

    public function test_framework_examples_are_documented(): void
    {
        $this->assertFileExists(getcwd() . '/examples/compiler-core/README.md');
        $this->assertFileExists(getcwd() . '/examples/extensions-migrations/README.md');
        $this->assertFileExists(getcwd() . '/examples/reference-blog/README.md');
        $this->assertFileExists(getcwd() . '/examples/architecture-tools/README.md');
        $this->assertFileExists(getcwd() . '/examples/execution-pipeline/README.md');
        $this->assertFileExists(getcwd() . '/examples/app-scaffolding/README.md');
        $this->assertFileExists(getcwd() . '/examples/integration-tooling/README.md');
    }

    public function test_example_feature_context_manifests_match_current_generator_output(): void
    {
        foreach ($this->exampleAppSlugs() as $slug) {
            $base = getcwd() . '/examples/' . $slug;
            $generator = new ContextManifestGenerator(Paths::fromCwd($base));

            foreach (glob($base . '/app/features/*/feature.yaml') ?: [] as $manifestPath) {
                $feature = basename(dirname($manifestPath));
                $manifest = Yaml::parseFile($manifestPath);

                $this->assertIsArray($manifest);

                $expected = Json::encode($generator->build($feature, $manifest), true) . "\n";
                $actualPath = dirname($manifestPath) . '/context.manifest.json';
                $actual = file_get_contents($actualPath);

                $this->assertNotFalse($actual);
                $this->assertSame($expected, $actual, sprintf('Stale context manifest in %s/%s.', $slug, $feature));
            }
        }
    }

    public function test_canonical_app_examples_keep_generated_output_uncommitted_but_compile_cleanly(): void
    {
        foreach ($this->exampleAppSlugs() as $slug) {
            $project = new TempProject();
            $repoRoot = getcwd() ?: '.';

            try {
                $sourceApp = $repoRoot . '/examples/' . $slug . '/app';
                $sourcePublic = $repoRoot . '/examples/' . $slug . '/public/index.php';

                $this->assertDirectoryDoesNotExist($sourceApp . '/generated');
                $this->assertFileDoesNotExist($sourcePublic);

                $this->copyDirectory($sourceApp, $project->root . '/app');
                chdir($project->root);

                $result = $this->runCommand(new Application(), ['foundry', 'compile', 'graph', '--json']);

                $this->assertSame(0, $result['status'], sprintf('Example %s failed to compile.', $slug));
                $this->assertFileExists($project->root . '/app/generated/routes.php');
                $this->assertFileExists($project->root . '/app/generated/feature_index.php');
                $this->assertFileExists($project->root . '/app/generated/schema_index.php');
                $this->assertFileExists($project->root . '/app/.foundry/build/graph/app_graph.json');
            } finally {
                chdir($repoRoot);
                $project->cleanup();
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private function exampleAppSlugs(): array
    {
        return ['hello-world', 'blog-api', 'workflow-events'];
    }

    /**
     * @return array<int,string>
     */
    private function catalogSections(): array
    {
        return ['canonical', 'reference', 'framework'];
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            self::fail('Missing example app source directory: ' . $source);
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
}
