<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generation\MigrationGenerator;
use Foundry\Generation\QueryGenerator;
use Foundry\Generation\SchemaGenerator;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class GenerationHelpersTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_query_generator_sorts_and_outputs_named_queries(): void
    {
        $sql = (new QueryGenerator())->generate(['b_query', 'a_query', 'a_query']);

        $this->assertStringContainsString('-- name: a_query', $sql);
        $this->assertStringContainsString('-- name: b_query', $sql);
    }

    public function test_schema_generator_builds_schema_from_fields(): void
    {
        $schema = (new SchemaGenerator())->fromFieldDefinition('input_title', [
            'fields' => [
                'title' => ['type' => 'string', 'required' => true, 'minLength' => 1],
                'slug' => ['type' => 'string', 'required' => false],
            ],
        ]);

        $this->assertSame('object', $schema['type']);
        $this->assertContains('title', $schema['required']);
        $this->assertArrayHasKey('slug', $schema['properties']);
    }

    public function test_migration_generator_writes_sql_file(): void
    {
        $definition = $this->project->root . '/migration.yaml';
        file_put_contents($definition, "name: add_posts\ntable: posts\n");

        $path = (new MigrationGenerator())->generate($definition, $this->project->root . '/database/migrations');

        $this->assertFileExists($path);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS posts', (string) file_get_contents($path));
    }
}
