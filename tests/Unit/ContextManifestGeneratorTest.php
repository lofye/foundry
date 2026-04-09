<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generation\ContextManifestGenerator;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ContextManifestGeneratorTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $base = $this->project->root . '/app/features/publish_post/tests';
        mkdir($base, 0777, true);

        file_put_contents($this->project->root . '/app/features/publish_post/feature.yaml', 'x');
        file_put_contents($this->project->root . '/app/features/publish_post/action.php', '<?php');
        file_put_contents($this->project->root . '/app/features/publish_post/input.schema.json', '{}');
        file_put_contents($this->project->root . '/app/features/publish_post/output.schema.json', '{}');
        file_put_contents($this->project->root . '/app/features/publish_post/tests/publish_post_contract_test.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_builds_and_writes_context_manifest(): void
    {
        $generator = new ContextManifestGenerator(Paths::fromCwd($this->project->root));
        $manifest = $generator->build('publish_post', [
            'kind' => 'http',
            'database' => ['queries' => ['insert_post']],
            'jobs' => ['dispatch' => ['notify_followers']],
            'events' => ['emit' => ['post.created']],
            'cache' => ['invalidate' => ['posts:list']],
            'tests' => ['required' => ['contract', 'feature']],
            'llm' => ['risk' => 'medium'],
        ]);

        $this->assertSame('publish_post', $manifest['feature']);
        $this->assertContains('app/features/publish_post/action.php', $manifest['relevant_files']);

        $path = $generator->write('publish_post', [
            'kind' => 'http',
            'database' => ['queries' => []],
            'jobs' => ['dispatch' => []],
            'events' => ['emit' => []],
            'cache' => ['invalidate' => []],
            'tests' => ['required' => []],
            'llm' => ['risk' => 'low'],
        ]);

        $this->assertFileExists($path);
    }

    public function test_build_uses_canonical_feature_directory_and_snake_case_test_identifiers(): void
    {
        $base = $this->project->root . '/app/features/context-persistence/tests';
        mkdir($base, 0777, true);

        file_put_contents($this->project->root . '/app/features/context-persistence/feature.yaml', 'x');
        file_put_contents($this->project->root . '/app/features/context-persistence/action.php', '<?php');
        file_put_contents($this->project->root . '/app/features/context-persistence/input.schema.json', '{}');
        file_put_contents($this->project->root . '/app/features/context-persistence/output.schema.json', '{}');
        file_put_contents($this->project->root . '/app/features/context-persistence/tests/context_persistence_contract_test.php', '<?php');

        $generator = new ContextManifestGenerator(Paths::fromCwd($this->project->root));
        $manifest = $generator->build('context-persistence', [
            'kind' => 'http',
            'database' => ['queries' => []],
            'jobs' => ['dispatch' => []],
            'events' => ['emit' => []],
            'cache' => ['invalidate' => []],
            'tests' => ['required' => ['contract', 'feature']],
            'llm' => ['risk' => 'medium'],
        ]);

        $this->assertSame('context-persistence', $manifest['feature']);
        $this->assertContains('app/features/context-persistence/action.php', $manifest['relevant_files']);
        $this->assertContains('context_persistence_contract_test', $manifest['tests']);
        $this->assertContains('app/features/context-persistence/input.schema.json', $manifest['contracts']);
    }
}
