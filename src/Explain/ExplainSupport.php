<?php
declare(strict_types=1);

namespace Foundry\Explain;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Support\CliCommandPrefix;
use Foundry\Support\Paths;

final class ExplainSupport
{
    /**
     * Map raw graph node types to the canonical explain subject kinds.
     */
    public static function canonicalSubjectKindForNodeType(string $type): ?string
    {
        return match ($type) {
            'feature' => 'feature',
            'route' => 'route',
            'event' => 'event',
            'workflow' => 'workflow',
            'job' => 'job',
            'schema' => 'schema',
            'pipeline_stage' => 'pipeline_stage',
            default => null,
        };
    }

    /**
     * Map raw graph node types to stable relationship kinds that are safe to expose.
     */
    public static function canonicalRelationshipKindForNodeType(string $type): ?string
    {
        return match ($type) {
            'feature' => 'feature',
            'route' => 'route',
            'event' => 'event',
            'workflow' => 'workflow',
            'job' => 'job',
            'schema' => 'schema',
            'pipeline_stage' => 'pipeline_stage',
            'guard' => 'guard',
            'permission' => 'permission',
            'notification' => 'notification',
            'query' => 'query',
            'cache' => 'cache',
            default => null,
        };
    }

    public static function nodeLabel(GraphNode $node): string
    {
        $payload = $node->payload();

        foreach (self::nodeAliases($node) as $alias) {
            if ($alias !== $node->id()) {
                return $alias;
            }
        }

        foreach (['resource', 'provider', 'stream', 'bundle', 'policy', 'role', 'index', 'key', 'path', 'name', 'feature'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $node->id();
    }

    /**
     * @return array<int,string>
     */
    public static function nodeAliases(GraphNode $node): array
    {
        $payload = $node->payload();
        $aliases = [$node->id()];

        switch ($node->type()) {
            case 'feature':
                $aliases[] = (string) ($payload['feature'] ?? '');
                break;
            case 'route':
                $aliases[] = self::normalizeRouteSignature((string) ($payload['signature'] ?? ''));
                $method = strtoupper(trim((string) ($payload['method'] ?? '')));
                $path = trim((string) ($payload['path'] ?? ''));
                if ($method !== '' && $path !== '') {
                    $aliases[] = $method . ' ' . $path;
                }
                break;
            case 'event':
            case 'job':
            case 'permission':
            case 'query':
            case 'notification':
                $aliases[] = (string) ($payload['name'] ?? '');
                break;
            case 'workflow':
                $aliases[] = (string) ($payload['resource'] ?? '');
                break;
            case 'schema':
                $aliases[] = (string) ($payload['path'] ?? '');
                break;
            case 'pipeline_stage':
                $aliases[] = (string) ($payload['name'] ?? '');
                break;
            case 'cache':
                $aliases[] = (string) ($payload['key'] ?? '');
                break;
            case 'billing':
                $aliases[] = (string) ($payload['provider'] ?? '');
                break;
            case 'orchestration':
                $aliases[] = (string) ($payload['name'] ?? '');
                break;
            case 'search_index':
                $aliases[] = (string) ($payload['index'] ?? '');
                break;
            case 'stream':
                $aliases[] = (string) ($payload['stream'] ?? '');
                break;
            case 'locale_bundle':
                $aliases[] = (string) ($payload['bundle'] ?? '');
                break;
            case 'role':
                $aliases[] = (string) ($payload['role'] ?? '');
                break;
            case 'policy':
                $aliases[] = (string) ($payload['policy'] ?? '');
                break;
            case 'inspect_ui':
                $aliases[] = (string) ($payload['name'] ?? '');
                break;
        }

        $aliases = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $aliases,
        ), static fn (string $value): bool => $value !== ''));
        sort($aliases);

        return array_values(array_unique($aliases));
    }

    public static function featureFromNode(GraphNode $node): ?string
    {
        $payload = $node->payload();
        $feature = trim((string) ($payload['feature'] ?? ''));
        if ($feature !== '') {
            return $feature;
        }

        $features = array_values(array_filter(array_map('strval', (array) ($payload['features'] ?? []))));
        if (count($features) === 1) {
            return $features[0];
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public static function summarizeGraphNode(GraphNode $node, ?string $edgeType = null): array
    {
        $kind = self::canonicalRelationshipKindForNodeType($node->type());

        return [
            'id' => $node->id(),
            'kind' => $kind ?? 'internal',
            'label' => self::nodeLabel($node),
            'feature' => self::featureFromNode($node),
            'source_path' => $node->sourcePath(),
            'edge_type' => $edgeType,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function summarizeGraphNodeById(ApplicationGraph $graph, string $nodeId, ?string $edgeType = null): array
    {
        $node = $graph->node($nodeId);
        if ($node === null) {
            return [
                'id' => $nodeId,
                'kind' => 'missing',
                'label' => $nodeId,
                'feature' => null,
                'source_path' => null,
                'edge_type' => $edgeType,
                'missing' => true,
            ];
        }

        return self::summarizeGraphNode($node, $edgeType);
    }

    public static function isRenderableRelationshipNode(GraphNode $node): bool
    {
        return self::canonicalRelationshipKindForNodeType($node->type()) !== null;
    }

    public static function normalizeRouteSignature(string $signature): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $signature) ?? $signature);
        if ($normalized === '') {
            return '';
        }

        [$method, $path] = explode(' ', $normalized, 2) + ['', ''];
        if ($path === '') {
            return $normalized;
        }

        return strtoupper(trim($method)) . ' ' . trim($path);
    }

    public static function routeNodeId(string $signature): string
    {
        [$method, $path] = explode(' ', self::normalizeRouteSignature($signature), 2) + ['', ''];

        return 'route:' . $method . ':' . $path;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function uniqueRows(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                $id = trim((string) ($row['label'] ?? ''));
            }
            if ($id === '') {
                $id = md5(serialize($row));
            }

            $unique[$id] = $row;
        }

        usort(
            $unique,
            static fn (array $left, array $right): int => strcmp((string) ($left['kind'] ?? ''), (string) ($right['kind'] ?? ''))
                ?: strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''))
                ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? '')),
        );

        return array_values($unique);
    }

    /**
     * @param array<int,string> $values
     * @return array<int,string>
     */
    public static function uniqueStrings(array $values): array
    {
        $values = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
        sort($values);

        return array_values(array_unique($values));
    }

    /**
     * @param array<int,string> $values
     * @return array<int,string>
     */
    public static function orderedUniqueStrings(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $normalized = trim((string) $value);
            if ($normalized === '' || in_array($normalized, $unique, true)) {
                continue;
            }

            $unique[] = $normalized;
        }

        return $unique;
    }

    public static function commandPrefix(Paths $paths): string
    {
        return CliCommandPrefix::foundry($paths);
    }

    /**
     * @param array<string,mixed> $items
     * @return array<string,mixed>
     */
    public static function section(string $id, string $title, array $items, ?string $shape = null): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'shape' => $shape ?? ExplainSection::inferShape($items),
            'items' => $items,
        ];
    }
}
