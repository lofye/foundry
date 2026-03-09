<?php
declare(strict_types=1);

namespace Foundry\Compiler\Analysis\Analyzers;

use Foundry\Compiler\Analysis\AnalyzerContext;
use Foundry\Compiler\Analysis\GraphAnalyzer;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Diagnostics\DiagnosticBag;

final class CacheTopologyAnalyzer implements GraphAnalyzer
{
    public function id(): string
    {
        return 'cache_topology';
    }

    public function description(): string
    {
        return 'Detects cache keys that are never invalidated and invalidation coverage gaps.';
    }

    /**
     * @return array<string,mixed>
     */
    public function analyze(ApplicationGraph $graph, AnalyzerContext $context, DiagnosticBag $diagnostics): array
    {
        $cacheKeysByNamespace = [];
        $neverInvalidated = [];
        $invalidationGaps = [];

        foreach ($graph->nodesByType('cache') as $cacheNode) {
            if (!$context->includesNode($cacheNode)) {
                continue;
            }

            $payload = $cacheNode->payload();
            $key = (string) ($payload['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $namespace = $this->namespace($key);
            $cacheKeysByNamespace[$namespace] ??= [];
            $cacheKeysByNamespace[$namespace][] = $key;

            $invalidatedBy = array_values(array_filter(array_map('strval', (array) ($payload['invalidated_by'] ?? []))));
            sort($invalidatedBy);
            if ($invalidatedBy !== []) {
                continue;
            }

            $neverInvalidated[] = $key;
            $diagnostics->warning(
                code: 'FDY9008_CACHE_NEVER_INVALIDATED',
                category: 'cache',
                message: sprintf('Cache key %s is never invalidated.', $key),
                nodeId: $cacheNode->id(),
                suggestedFix: 'Add this key to at least one feature cache.invalidate list.',
                pass: 'doctor.' . $this->id(),
            );
        }

        foreach ($cacheKeysByNamespace as &$keys) {
            sort($keys);
            $keys = array_values(array_unique($keys));
        }
        unset($keys);

        foreach ($graph->nodesByType('feature') as $featureNode) {
            $payload = $featureNode->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature === '' || !$context->includesFeature($feature)) {
                continue;
            }

            $invalidates = array_values(array_filter(array_map('strval', (array) ($payload['cache']['invalidate'] ?? []))));
            sort($invalidates);
            $invalidates = array_values(array_unique($invalidates));

            if ($invalidates === []) {
                continue;
            }

            $namespaces = [];
            foreach ($invalidates as $key) {
                $namespaces[] = $this->namespace($key);
            }
            $namespaces = array_values(array_unique($namespaces));
            sort($namespaces);

            foreach ($namespaces as $namespace) {
                $group = $cacheKeysByNamespace[$namespace] ?? [];
                if (count($group) <= 1) {
                    continue;
                }

                $missing = array_values(array_diff($group, $invalidates));
                sort($missing);
                if ($missing === []) {
                    continue;
                }

                $invalidationGaps[] = [
                    'feature' => $feature,
                    'namespace' => $namespace,
                    'missing_keys' => $missing,
                ];

                $diagnostics->info(
                    code: 'FDY9009_CACHE_INVALIDATION_GAP',
                    category: 'cache',
                    message: sprintf(
                        'Feature %s invalidates %s namespace partially; missing keys: %s.',
                        $feature,
                        $namespace,
                        implode(', ', $missing),
                    ),
                    nodeId: $featureNode->id(),
                    pass: 'doctor.' . $this->id(),
                );
            }
        }

        sort($neverInvalidated);
        $neverInvalidated = array_values(array_unique($neverInvalidated));
        usort(
            $invalidationGaps,
            static fn (array $a, array $b): int => strcmp(
                (string) ($a['feature'] ?? '') . ':' . (string) ($a['namespace'] ?? ''),
                (string) ($b['feature'] ?? '') . ':' . (string) ($b['namespace'] ?? ''),
            ),
        );

        return [
            'never_invalidated' => $neverInvalidated,
            'invalidation_gaps' => $invalidationGaps,
        ];
    }

    private function namespace(string $key): string
    {
        $parts = explode(':', $key, 2);

        return (string) ($parts[0] ?? $key);
    }
}

