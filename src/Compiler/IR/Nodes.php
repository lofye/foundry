<?php
declare(strict_types=1);

namespace Foundry\Compiler\IR;

interface GraphNode
{
    public function id(): string;

    public function type(): string;

    public function sourcePath(): string;

    /**
     * @return array{line_start:int|null,line_end:int|null}|null
     */
    public function sourceRegion(): ?array;

    /**
     * @return array<string,mixed>
     */
    public function payload(): array;

    /**
     * @return array<int,int>
     */
    public function graphCompatibility(): array;

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array;
}

abstract class AbstractNode implements GraphNode
{
    /**
     * @param array<string,mixed> $payload
     * @param array{line_start:int|null,line_end:int|null}|null $sourceRegion
     * @param array<int,int> $graphCompatibility
     */
    final public function __construct(
        private readonly string $id,
        private readonly string $sourcePath,
        private readonly array $payload,
        private readonly ?array $sourceRegion = null,
        private readonly array $graphCompatibility = [1],
    ) {
    }

    final public function id(): string
    {
        return $this->id;
    }

    final public function sourcePath(): string
    {
        return $this->sourcePath;
    }

    final public function sourceRegion(): ?array
    {
        return $this->sourceRegion;
    }

    final public function payload(): array
    {
        return $this->payload;
    }

    final public function graphCompatibility(): array
    {
        return $this->graphCompatibility;
    }

    /**
     * @return array<string,mixed>
     */
    final public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'type' => $this->type(),
            'source_path' => $this->sourcePath(),
            'source_region' => $this->sourceRegion(),
            'payload' => $this->payload(),
            'graph_compatibility' => $this->graphCompatibility(),
        ];
    }
}

final class FeatureNode extends AbstractNode
{
    public function type(): string
    {
        return 'feature';
    }
}

final class RouteNode extends AbstractNode
{
    public function type(): string
    {
        return 'route';
    }
}

final class SchemaNode extends AbstractNode
{
    public function type(): string
    {
        return 'schema';
    }
}

final class PermissionNode extends AbstractNode
{
    public function type(): string
    {
        return 'permission';
    }
}

final class QueryNode extends AbstractNode
{
    public function type(): string
    {
        return 'query';
    }
}

final class EventNode extends AbstractNode
{
    public function type(): string
    {
        return 'event';
    }
}

final class JobNode extends AbstractNode
{
    public function type(): string
    {
        return 'job';
    }
}

final class CacheNode extends AbstractNode
{
    public function type(): string
    {
        return 'cache';
    }
}

final class SchedulerNode extends AbstractNode
{
    public function type(): string
    {
        return 'scheduler';
    }
}

final class WebhookNode extends AbstractNode
{
    public function type(): string
    {
        return 'webhook';
    }
}

final class TestNode extends AbstractNode
{
    public function type(): string
    {
        return 'test';
    }
}

final class ContextManifestNode extends AbstractNode
{
    public function type(): string
    {
        return 'context_manifest';
    }
}

final class AuthNode extends AbstractNode
{
    public function type(): string
    {
        return 'auth';
    }
}

final class RateLimitNode extends AbstractNode
{
    public function type(): string
    {
        return 'rate_limit';
    }
}

final class PipelineStageNode extends AbstractNode
{
    public function type(): string
    {
        return 'pipeline_stage';
    }
}

final class GuardNode extends AbstractNode
{
    public function type(): string
    {
        return 'guard';
    }
}

final class InterceptorNode extends AbstractNode
{
    public function type(): string
    {
        return 'interceptor';
    }
}

final class ExecutionPlanNode extends AbstractNode
{
    public function type(): string
    {
        return 'execution_plan';
    }
}

final class StarterKitNode extends AbstractNode
{
    public function type(): string
    {
        return 'starter_kit';
    }
}

final class ResourceNode extends AbstractNode
{
    public function type(): string
    {
        return 'resource';
    }
}

final class AdminResourceNode extends AbstractNode
{
    public function type(): string
    {
        return 'admin_resource';
    }
}

final class UploadProfileNode extends AbstractNode
{
    public function type(): string
    {
        return 'upload_profile';
    }
}

final class ListingConfigNode extends AbstractNode
{
    public function type(): string
    {
        return 'listing_config';
    }
}

final class FormDefinitionNode extends AbstractNode
{
    public function type(): string
    {
        return 'form_definition';
    }
}

final class NotificationNode extends AbstractNode
{
    public function type(): string
    {
        return 'notification';
    }
}

final class ApiResourceNode extends AbstractNode
{
    public function type(): string
    {
        return 'api_resource';
    }
}

final class NodeFactory
{
    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): GraphNode
    {
        $id = (string) ($row['id'] ?? '');
        $type = (string) ($row['type'] ?? '');
        $sourcePath = (string) ($row['source_path'] ?? '');
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $sourceRegionRaw = $row['source_region'] ?? null;
        $sourceRegion = is_array($sourceRegionRaw) ? [
            'line_start' => isset($sourceRegionRaw['line_start']) ? (int) $sourceRegionRaw['line_start'] : null,
            'line_end' => isset($sourceRegionRaw['line_end']) ? (int) $sourceRegionRaw['line_end'] : null,
        ] : null;
        $compatibility = array_values(array_map('intval', (array) ($row['graph_compatibility'] ?? [1])));

        return match ($type) {
            'feature' => new FeatureNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'route' => new RouteNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'schema' => new SchemaNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'permission' => new PermissionNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'query' => new QueryNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'event' => new EventNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'job' => new JobNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'cache' => new CacheNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'scheduler' => new SchedulerNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'webhook' => new WebhookNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'test' => new TestNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'context_manifest' => new ContextManifestNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'auth' => new AuthNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'rate_limit' => new RateLimitNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'pipeline_stage' => new PipelineStageNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'guard' => new GuardNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'interceptor' => new InterceptorNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'execution_plan' => new ExecutionPlanNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'starter_kit' => new StarterKitNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'resource' => new ResourceNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'admin_resource' => new AdminResourceNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'upload_profile' => new UploadProfileNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'listing_config' => new ListingConfigNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'form_definition' => new FormDefinitionNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'notification' => new NotificationNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            'api_resource' => new ApiResourceNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
            default => new FeatureNode($id, $sourcePath, $payload, $sourceRegion, $compatibility),
        };
    }
}
