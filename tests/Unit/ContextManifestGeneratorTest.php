<?php
declare(strict_types=1);

namespace Forge\Tests\Unit;

use Forge\Generation\ContextManifestGenerator;
use Forge\Support\Paths;
use Forge\Tests\Fixtures\TempProject;
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
}
