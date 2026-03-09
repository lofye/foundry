<?php
declare(strict_types=1);

namespace Foundry\Compiler\Analysis\Analyzers;

use Foundry\Compiler\Analysis\AnalyzerContext;
use Foundry\Compiler\Analysis\GraphAnalyzer;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Diagnostics\DiagnosticBag;

final class DeadCodeAnalyzer implements GraphAnalyzer
{
    public function id(): string
    {
        return 'dead_code';
    }

    public function description(): string
    {
        return 'Finds unused features, queries, and events with no subscribers.';
    }

    /**
     * @return array<string,mixed>
     */
    public function analyze(ApplicationGraph $graph, AnalyzerContext $context, DiagnosticBag $diagnostics): array
    {
        $orphanFeatures = [];
        $unusedQueries = [];
        $eventsWithoutSubscribers = [];

        foreach ($graph->nodesByType('feature') as $featureNode) {
            $payload = $featureNode->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature === '' || !$context->includesFeature($feature)) {
                continue;
            }

            $kind = (string) ($payload['kind'] ?? '');
            $route = $payload['route'] ?? null;

            if ($kind === 'http' && !is_array($route)) {
                $orphanFeatures[] = $feature;
                $diagnostics->warning(
                    code: 'FDY9005_FEATURE_NO_ROUTE',
                    category: 'graph',
                    message: sprintf('Feature %s has no route referencing it.', $feature),
                    nodeId: $featureNode->id(),
                    suggestedFix: 'Add route.method and route.path in feature.yaml or change feature kind.',
                    pass: 'doctor.' . $this->id(),
                );
            }
        }

        foreach ($graph->nodesByType('query') as $queryNode) {
            $payload = $queryNode->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature !== '' && !$context->includesFeature($feature)) {
                continue;
            }

            $defined = (bool) ($payload['defined'] ?? false);
            $referenced = (bool) ($payload['referenced'] ?? false);
            if (!$defined || $referenced) {
                continue;
            }

            $queryName = (string) ($payload['name'] ?? $queryNode->id());
            $unusedQueries[] = $queryName;
            $diagnostics->info(
                code: 'FDY9006_QUERY_DEAD_CODE',
                category: 'queries',
                message: sprintf('Query %s is never used.', $queryName),
                nodeId: $queryNode->id(),
                pass: 'doctor.' . $this->id(),
            );
        }

        foreach ($graph->nodesByType('event') as $eventNode) {
            if (!$context->includesNode($eventNode)) {
                continue;
            }

            $payload = $eventNode->payload();
            $emitters = array_values(array_filter(array_map('strval', (array) ($payload['emitters'] ?? []))));
            $subscribers = array_values(array_filter(array_map('strval', (array) ($payload['subscribers'] ?? []))));
            if ($emitters === [] || $subscribers !== []) {
                continue;
            }

            $name = (string) ($payload['name'] ?? $eventNode->id());
            $eventsWithoutSubscribers[] = $name;
            $diagnostics->info(
                code: 'FDY9007_EVENT_NO_SUBSCRIBERS',
                category: 'events',
                message: sprintf('Event %s has no subscribers.', $name),
                nodeId: $eventNode->id(),
                pass: 'doctor.' . $this->id(),
            );
        }

        sort($orphanFeatures);
        $orphanFeatures = array_values(array_unique($orphanFeatures));
        sort($unusedQueries);
        $unusedQueries = array_values(array_unique($unusedQueries));
        sort($eventsWithoutSubscribers);
        $eventsWithoutSubscribers = array_values(array_unique($eventsWithoutSubscribers));

        return [
            'orphan_features' => $orphanFeatures,
            'unused_queries' => $unusedQueries,
            'events_without_subscribers' => $eventsWithoutSubscribers,
        ];
    }
}

