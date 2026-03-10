<?php
declare(strict_types=1);

namespace Foundry\Compiler\Projection;

use Foundry\Compiler\ApplicationGraph;

final class IntegrationProjectionEmitters
{
    /**
     * @return array<int,ProjectionEmitter>
     */
    public static function all(): array
    {
        return [
            new GenericProjectionEmitter('notification', 'notification_index.php', null, self::notificationBuilder()),
            new GenericProjectionEmitter('api_resource', 'api_resource_index.php', null, self::apiResourceBuilder()),
        ];
    }

    /**
     * @return callable(ApplicationGraph):array<string,mixed>
     */
    private static function notificationBuilder(): callable
    {
        return static function (ApplicationGraph $graph): array {
            $rows = [];
            foreach ($graph->nodesByType('notification') as $node) {
                $payload = $node->payload();
                $name = (string) ($payload['notification'] ?? '');
                if ($name === '') {
                    continue;
                }

                $rows[$name] = $payload;
            }

            ksort($rows);

            return $rows;
        };
    }

    /**
     * @return callable(ApplicationGraph):array<string,mixed>
     */
    private static function apiResourceBuilder(): callable
    {
        return static function (ApplicationGraph $graph): array {
            $rows = [];
            foreach ($graph->nodesByType('api_resource') as $node) {
                $payload = $node->payload();
                $resource = (string) ($payload['resource'] ?? '');
                if ($resource === '') {
                    continue;
                }

                $rows[$resource] = $payload;
            }

            ksort($rows);

            return $rows;
        };
    }
}
