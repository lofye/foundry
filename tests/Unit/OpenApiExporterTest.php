<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\FeatureNode;
use Foundry\Export\OpenApiExporter;
use PHPUnit\Framework\TestCase;

final class OpenApiExporterTest extends TestCase
{
    public function test_builds_openapi_document_from_api_features(): void
    {
        $graph = new ApplicationGraph(
            graphVersion: 1,
            frameworkVersion: '0.4.0',
            compiledAt: '2026-03-09T00:00:00+00:00',
            sourceHash: 'abc123',
        );

        $graph->addNode(new FeatureNode(
            id: 'feature:api_list_posts',
            sourcePath: 'app/features/api_list_posts/feature.yaml',
            payload: [
                'feature' => 'api_list_posts',
                'description' => 'List posts.',
                'route' => ['method' => 'GET', 'path' => '/api/posts'],
                'input_schema_path' => 'app/features/api_list_posts/input.schema.json',
                'output_schema_path' => 'app/features/api_list_posts/output.schema.json',
                'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
                'output_schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['data' => ['type' => 'array']]],
                'auth' => ['required' => true, 'permissions' => ['posts.view']],
                'resource' => ['name' => 'posts'],
            ],
            sourceRegion: ['line_start' => 1, 'line_end' => null],
            graphCompatibility: [1],
        ));

        $exporter = new OpenApiExporter();
        $document = $exporter->build($graph);

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertArrayHasKey('/api/posts', $document['paths']);
        $this->assertArrayHasKey('get', $document['paths']['/api/posts']);
        $this->assertSame('api_list_posts', $document['paths']['/api/posts']['get']['operationId']);
        $this->assertSame([['bearerAuth' => []]], $document['paths']['/api/posts']['get']['security']);
        $this->assertArrayHasKey('ErrorEnvelope', $document['components']['schemas']);

        $json = $exporter->render($document, 'json');
        $yaml = $exporter->render($document, 'yaml');

        $this->assertStringContainsString('"openapi": "3.1.0"', $json);
        $this->assertStringContainsString('openapi: 3.1.0', $yaml);
    }
}
