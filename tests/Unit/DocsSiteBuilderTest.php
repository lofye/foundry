<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\CacheNode;
use Foundry\Compiler\IR\EventNode;
use Foundry\Compiler\IR\FeatureNode;
use Foundry\Compiler\IR\JobNode;
use Foundry\Compiler\IR\RouteNode;
use Foundry\Compiler\IR\SchemaNode;
use Foundry\Documentation\DocsSiteBuilder;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class DocsSiteBuilderTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        mkdir($this->project->root . '/docs', 0777, true);
        mkdir($this->project->root . '/docs/versions/v0.4.0', 0777, true);
        mkdir($this->project->root . '/examples/hello-world', 0777, true);

        file_put_contents($this->project->root . '/docs/intro.md', <<<'MD'
# Foundry Docs

Read the [Quick Tour](quick-tour.md), [How It Works](how-it-works.md), and [Reference](reference.md).
MD);
        file_put_contents($this->project->root . '/docs/quick-tour.md', <<<'MD'
# Quick Tour

Jump from [Intro](intro.md) to [Graph Overview](graph-overview.md).
MD);
        file_put_contents($this->project->root . '/docs/how-it-works.md', <<<'MD'
# How It Works

The graph-backed docs system mirrors the compiler structure.
MD);
        file_put_contents($this->project->root . '/docs/reference.md', <<<'MD'
# Reference

See the [CLI Reference](cli-reference.md) and [Feature Catalog](features.md).
MD);
        file_put_contents($this->project->root . '/docs/app-scaffolding.md', "# App Scaffolding\n");
        file_put_contents($this->project->root . '/docs/example-applications.md', <<<'MD'
# Example Applications

- [Hello World](../examples/hello-world/README.md)
MD);
        file_put_contents($this->project->root . '/docs/semantic-compiler.md', "# Semantic Compiler\n");
        file_put_contents($this->project->root . '/docs/execution-pipeline.md', "# Execution Pipeline\n");
        file_put_contents($this->project->root . '/docs/architecture-tools.md', "# Architecture Tools\n");
        file_put_contents($this->project->root . '/docs/contributor-vocabulary.md', "# Contributor Vocabulary\n");
        file_put_contents($this->project->root . '/docs/public-api-policy.md', "# Public API Policy\n");
        file_put_contents($this->project->root . '/docs/extension-author-guide.md', "# Extension Author Guide\n");
        file_put_contents($this->project->root . '/docs/extensions-and-migrations.md', "# Extensions And Migrations\n");
        file_put_contents($this->project->root . '/docs/upgrade-safety.md', "# Upgrade Safety\n");
        file_put_contents($this->project->root . '/docs/api-notifications-docs.md', "# API And Notifications\n");

        file_put_contents($this->project->root . '/docs/versions/v0.4.0/index.md', <<<'MD'
# Foundry Docs v0.4.0

Archived intro for the v0.4.0 snapshot.
MD);
        file_put_contents($this->project->root . '/docs/versions/v0.4.0/quick-tour.md', <<<'MD'
# Quick Tour v0.4.0

Snapshot quick tour content.
MD);
        file_put_contents($this->project->root . '/docs/versions/v0.4.0/reference.html', '<h1>Reference v0.4.0</h1><p>Archived HTML page.</p>');
        file_put_contents($this->project->root . '/examples/hello-world/README.md', "# Hello World Example\n\nExample readme content.\n");
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_builds_legacy_local_preview_site_with_current_and_versioned_outputs(): void
    {
        $builder = new DocsSiteBuilder(Paths::fromCwd($this->project->root), new ApiSurfaceRegistry());
        $result = $builder->build($this->graph(), '0.4.1');

        $this->assertSame('legacy_local_preview', $result['mode']);
        $this->assertSame('deprecated', $result['deprecation']['status']);
        $this->assertSame('framework/docs', $result['deprecation']['authoritative_source']);
        $this->assertSame('website_repo', $result['deprecation']['authoritative_publisher']);
        $this->assertSame('v0.4.1', $result['current_version']);
        $this->assertFileExists($this->project->root . '/public/docs/index.html');
        $this->assertFileExists($this->project->root . '/public/docs/reference.html');
        $this->assertFileExists($this->project->root . '/public/docs/features.html');
        $this->assertFileExists($this->project->root . '/public/docs/graph-overview.html');
        $this->assertFileExists($this->project->root . '/public/docs/architecture-explorer.html');
        $this->assertFileExists($this->project->root . '/public/docs/example-hello-world.html');
        $this->assertFileExists($this->project->root . '/public/docs/versions/index.html');
        $this->assertFileExists($this->project->root . '/public/docs/versions/v0.4.1/index.html');
        $this->assertFileExists($this->project->root . '/public/docs/versions/v0.4.0/index.html');

        $manifest = Json::decodeAssoc((string) file_get_contents($this->project->root . '/public/docs/manifest.json'));
        $this->assertSame('legacy_local_preview', $manifest['mode']);
        $this->assertSame('deprecated', $manifest['deprecation']['status']);
        $this->assertSame('v0.4.1', $manifest['current_version']);
        $this->assertSame('v0.4.1', $manifest['versions'][0]['version']);
        $this->assertSame('v0.4.0', $manifest['versions'][1]['version']);
        $this->assertContains('architecture-explorer.html', array_column((array) $manifest['pages'], 'path'));

        $home = (string) file_get_contents($this->project->root . '/public/docs/index.html');
        $this->assertStringContainsString('Legacy local preview only.', $home);
        $this->assertStringContainsString('href="quick-tour.html"', $home);
        $this->assertStringContainsString('href="how-it-works.html"', $home);
        $this->assertStringContainsString('href="reference.html"', $home);
        $this->assertStringContainsString('href="versions/index.html"', $home);
        $this->assertStringContainsString('Getting Started', $home);

        $graphOverview = (string) file_get_contents($this->project->root . '/public/docs/graph-overview.html');
        $this->assertStringContainsString('inspect graph --json', $graphOverview);
        $this->assertStringContainsString('list_posts', $graphOverview);

        $features = (string) file_get_contents($this->project->root . '/public/docs/features.html');
        $this->assertStringContainsString('Feature Catalog', $features);
        $this->assertStringContainsString('list_posts', $features);
        $this->assertStringContainsString('GET /posts', $features);

        $examples = (string) file_get_contents($this->project->root . '/public/docs/example-applications.html');
        $this->assertStringContainsString('href="example-hello-world.html"', $examples);

        $explorer = (string) file_get_contents($this->project->root . '/public/docs/architecture-explorer.html');
        $this->assertStringContainsString('Architecture Explorer', $explorer);
        $this->assertStringContainsString('architecture-graph-data', $explorer);
        $this->assertStringContainsString('Search node name, type, or label', $explorer);
        $this->assertStringContainsString('feature:list_posts', $explorer);
        $this->assertStringContainsString('Open related docs page', $explorer);
    }

    public function test_uses_legacy_snapshot_sources_for_archived_preview_versions(): void
    {
        $builder = new DocsSiteBuilder(Paths::fromCwd($this->project->root), new ApiSurfaceRegistry());
        $builder->build($this->graph(), '0.4.1');

        $snapshotIndex = (string) file_get_contents($this->project->root . '/public/docs/versions/v0.4.0/index.html');
        $snapshotReference = (string) file_get_contents($this->project->root . '/public/docs/versions/v0.4.0/reference.html');
        $versionsIndex = (string) file_get_contents($this->project->root . '/public/docs/versions/index.html');
        $versionsManifest = Json::decodeAssoc((string) file_get_contents($this->project->root . '/public/docs/versions.json'));

        $this->assertSame('legacy_local_preview', $versionsManifest['mode']);
        $this->assertSame('docs/versions is deprecated as a publishing source. The website repo owns authoritative published version snapshots.', $versionsManifest['deprecation']['snapshot_notice']);
        $this->assertStringContainsString('Foundry Docs v0.4.0', $snapshotIndex);
        $this->assertStringContainsString('Snapshot quick tour content', (string) file_get_contents($this->project->root . '/public/docs/versions/v0.4.0/quick-tour.html'));
        $this->assertStringContainsString('Archived HTML page.', $snapshotReference);
        $this->assertStringContainsString('Legacy local preview only.', $versionsIndex);
        $this->assertStringContainsString('Framework tag: v0.4.0', $versionsIndex);
        $this->assertStringContainsString('href="../index.html"', $versionsIndex);
    }

    private function graph(): ApplicationGraph
    {
        $graph = new ApplicationGraph(1, '0.4.1', '2026-03-20T00:00:00+00:00', 'abc123');
        $graph->addNode(new FeatureNode('feature:list_posts', 'app/features/list_posts/feature.yaml', [
            'feature' => 'list_posts',
            'kind' => 'http',
            'route' => ['method' => 'GET', 'path' => '/posts'],
            'input_schema_path' => 'app/features/list_posts/input.schema.json',
            'output_schema_path' => 'app/features/list_posts/output.schema.json',
            'auth' => ['required' => false, 'strategies' => [], 'permissions' => []],
            'database' => ['reads' => ['posts'], 'writes' => []],
            'events' => ['emit' => ['post.listed']],
            'jobs' => ['dispatch' => ['warm_posts_cache']],
            'tests' => ['required' => ['feature']],
        ]));
        $graph->addNode(new RouteNode('route:GET:/posts', 'app/features/list_posts/feature.yaml', ['signature' => 'GET /posts', 'features' => ['list_posts']]));
        $graph->addNode(new SchemaNode('schema:app/features/list_posts/input.schema.json', 'app/features/list_posts/input.schema.json', ['path' => 'app/features/list_posts/input.schema.json', 'role' => 'input', 'feature' => 'list_posts']));
        $graph->addNode(new SchemaNode('schema:app/features/list_posts/output.schema.json', 'app/features/list_posts/output.schema.json', ['path' => 'app/features/list_posts/output.schema.json', 'role' => 'output', 'feature' => 'list_posts']));
        $graph->addNode(new EventNode('event:post.listed', 'app/features/list_posts/events.yaml', ['name' => 'post.listed', 'emitters' => ['list_posts'], 'subscribers' => []]));
        $graph->addNode(new JobNode('job:warm_posts_cache', 'app/features/list_posts/jobs.yaml', ['name' => 'warm_posts_cache', 'features' => ['list_posts']]));
        $graph->addNode(new CacheNode('cache:posts:list', 'app/features/list_posts/cache.yaml', ['key' => 'posts:list', 'invalidated_by' => ['list_posts']]));

        return $graph;
    }
}
