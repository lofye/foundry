<?php

declare(strict_types=1);

namespace Foundry\Compiler\Projection;

use Foundry\Compiler\ApplicationGraph;

final class PlatformProjectionEmitters
{
    /**
     * @return array<int,ProjectionEmitter>
     */
    public static function all(): array
    {
        return [
            new GenericProjectionEmitter('billing', 'billing_index.php', null, self::by('billing', 'provider')),
            new GenericProjectionEmitter('workflow', 'workflow_index.php', null, self::by('workflow', 'resource')),
            new GenericProjectionEmitter('orchestration', 'orchestration_index.php', null, self::by('orchestration', 'name')),
            new GenericProjectionEmitter('search_index', 'search_index.php', null, self::by('search_index', 'index')),
            new GenericProjectionEmitter('stream', 'stream_index.php', null, self::by('stream', 'stream')),
            new GenericProjectionEmitter('locale_bundle', 'locale_index.php', null, self::by('locale_bundle', 'bundle')),
            new GenericProjectionEmitter('role', 'role_index.php', null, self::by('role', 'role')),
            new GenericProjectionEmitter('policy', 'policy_index.php', null, self::by('policy', 'policy')),
            new GenericProjectionEmitter('inspect_ui', 'inspect_ui_index.php', null, self::by('inspect_ui', 'name')),
        ];
    }

    /**
     * @return callable(ApplicationGraph):array<string,mixed>
     */
    private static function by(string $type, string $key): callable
    {
        return static function (ApplicationGraph $graph) use ($type, $key): array {
            $rows = [];
            foreach ($graph->nodesByType($type) as $node) {
                $payload = $node->payload();
                $id = (string) ($payload[$key] ?? '');
                if ($id === '') {
                    continue;
                }

                $rows[$id] = $payload;
            }

            ksort($rows);

            return $rows;
        };
    }
}
