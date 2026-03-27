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
    public function test_official_example_catalog_entries_have_directories_readmes_and_metadata(): void
    {
        /** @var array<string,mixed> $catalog */
        $catalog = require getcwd() . '/examples/catalog.php';

        foreach ((array) ($catalog['official'] ?? []) as $example) {
            $this->assertIsArray($example);

            $path = getcwd() . '/' . (string) ($example['path'] ?? '');
            $this->assertDirectoryExists($path);
            $this->assertFileExists($path . '/README.md');
            $this->assertNotEmpty((array) ($example['teaches'] ?? []));
        }

        $thresholds = (array) ($catalog['thresholds'] ?? []);
        $this->assertSame('Thresholds', $thresholds['title'] ?? null);
        $this->assertSame('real-app-reference', $thresholds['position'] ?? null);
    }

    public function test_examples_docs_index_and_thresholds_alignment_are_present(): void
    {
        /** @var array<string,mixed> $catalog */
        $catalog = require getcwd() . '/examples/catalog.php';
        $docs = file_get_contents(getcwd() . '/docs/example-applications.md') ?: '';
        $index = file_get_contents(getcwd() . '/examples/README.md') ?: '';

        foreach ((array) ($catalog['official'] ?? []) as $example) {
            $this->assertIsArray($example);

            $title = (string) ($example['title'] ?? '');
            $path = (string) ($example['path'] ?? '');

            $this->assertStringContainsString($title, $docs);
            $this->assertStringContainsString('../' . $path . '/README.md', $docs);
            $this->assertStringContainsString(substr($path, strlen('examples/')) . '/README.md', $index);
        }

        $this->assertStringContainsString('Thresholds', $docs);
        $this->assertStringContainsString('Thresholds', $index);
        $this->assertStringContainsString('docs/example-applications.md', (string) file_get_contents(getcwd() . '/README.md'));
    }

    public function test_blog_api_example_contains_required_features(): void
    {
        $base = getcwd() . '/examples/blog-api/app/features';
        foreach (['list_posts', 'view_post', 'publish_post', 'update_post', 'delete_post'] as $feature) {
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

    public function test_dashboard_example_contains_required_features(): void
    {
        $base = getcwd() . '/examples/dashboard/app/features';
        foreach (['login', 'current_user', 'list_notifications', 'upload_avatar'] as $feature) {
            $this->assertDirectoryExists($base . '/' . $feature);
            $this->assertFileExists($base . '/' . $feature . '/feature.yaml');
        }
    }

    public function test_ai_pipeline_example_contains_required_features(): void
    {
        $base = getcwd() . '/examples/ai-pipeline/app/features';
        foreach (['submit_document', 'extract_summary', 'classify_document', 'queue_ai_summary_job', 'fetch_ai_result'] as $feature) {
            $this->assertDirectoryExists($base . '/' . $feature);
            $this->assertFileExists($base . '/' . $feature . '/feature.yaml');
        }
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

    public function test_example_generated_projections_match_current_compile_output(): void
    {
        foreach ($this->exampleAppSlugs() as $slug) {
            $project = new TempProject();
            $repoRoot = getcwd() ?: '.';

            try {
                $sourceApp = $repoRoot . '/examples/' . $slug . '/app';
                $sourceGenerated = $sourceApp . '/generated';

                $this->copyDirectory($sourceApp, $project->root . '/app');
                chdir($project->root);

                $result = $this->runCommand(new Application(), ['foundry', 'compile', 'graph', '--json']);

                $this->assertSame(0, $result['status'], sprintf('Example %s failed to compile.', $slug));
                $this->assertDirectoriesMatch(
                    $sourceGenerated,
                    $project->root . '/app/generated',
                    sprintf('Stale generated projections in %s.', $slug),
                );
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
        return ['hello-world', 'blog-api', 'dashboard', 'ai-pipeline', 'workflow-events'];
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

    private function assertDirectoriesMatch(string $expectedDir, string $actualDir, string $message): void
    {
        $expected = $this->directoryContents($expectedDir);
        $actual = $this->directoryContents($actualDir);

        $this->assertSame(array_keys($expected), array_keys($actual), $message);

        foreach ($expected as $relativePath => $contents) {
            $this->assertArrayHasKey($relativePath, $actual, $message);
            $this->assertSame($contents, $actual[$relativePath], $message . ' File: ' . $relativePath);
        }
    }

    /**
     * @return array<string,string>
     */
    private function directoryContents(string $path): array
    {
        $contents = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            $relativePath = substr($item->getPathname(), strlen($path) + 1);
            $fileContents = file_get_contents($item->getPathname());

            $this->assertNotFalse($fileContents);
            $contents[$relativePath] = $fileContents;
        }

        ksort($contents);

        return $contents;
    }
}
