<?php
declare(strict_types=1);

namespace Foundry\Compiler\Analysis;

use Foundry\Compiler\IR\GraphNode;

final readonly class AnalyzerContext
{
    public function __construct(
        public ?string $featureFilter = null,
    ) {
    }

    public function includesFeature(string $feature): bool
    {
        if ($this->featureFilter === null || $this->featureFilter === '') {
            return true;
        }

        return $this->featureFilter === $feature;
    }

    public function includesNode(GraphNode $node): bool
    {
        if ($this->featureFilter === null || $this->featureFilter === '') {
            return true;
        }

        $payload = $node->payload();
        $feature = (string) ($payload['feature'] ?? '');
        if ($feature !== '') {
            return $this->includesFeature($feature);
        }

        return match ($node->type()) {
            'feature' => $node->id() === ('feature:' . $this->featureFilter),
            'route' => in_array($this->featureFilter, array_map('strval', (array) ($payload['features'] ?? [])), true),
            'event' => in_array($this->featureFilter, array_map('strval', (array) ($payload['emitters'] ?? [])), true)
                || in_array($this->featureFilter, array_map('strval', (array) ($payload['subscribers'] ?? [])), true),
            'permission' => in_array($this->featureFilter, array_map('strval', (array) ($payload['features'] ?? [])), true)
                || in_array($this->featureFilter, array_map('strval', (array) ($payload['declared_by'] ?? [])), true)
                || in_array($this->featureFilter, array_map('strval', (array) ($payload['referenced_by'] ?? [])), true),
            'cache' => in_array($this->featureFilter, array_map('strval', (array) ($payload['features'] ?? [])), true)
                || in_array($this->featureFilter, array_map('strval', (array) ($payload['invalidated_by'] ?? [])), true),
            'execution_plan' => $feature === '' ? (str_ends_with($node->id(), ':' . $this->featureFilter)) : $this->includesFeature($feature),
            'guard' => $this->includesFeature((string) ($payload['feature'] ?? '')),
            'interceptor' => true,
            'pipeline_stage' => true,
            'resource' => in_array($this->featureFilter, array_map('strval', (array) ($payload['feature_map'] ?? [])), true),
            'admin_resource' => in_array($this->featureFilter, array_map('strval', (array) ($payload['feature_map'] ?? [])), true),
            'upload_profile' => in_array($this->featureFilter, array_map('strval', (array) ($payload['feature_map'] ?? [])), true),
            'listing_config' => $this->includesFeature('list_' . (string) ($payload['resource'] ?? ''))
                || $this->includesFeature('admin_list_' . (string) ($payload['resource'] ?? '')),
            'form_definition' => $this->includesFeature((string) ($payload['feature'] ?? '')),
            default => false,
        };
    }
}
