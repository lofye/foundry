<?php

declare(strict_types=1);

namespace Foundry\Compiler\Projection;

use Foundry\Compiler\ApplicationGraph;

final class FoundationProjectionEmitters
{
    /**
     * @return array<int,ProjectionEmitter>
     */
    public static function all(): array
    {
        return [
            new GenericProjectionEmitter('starter', 'starter_index.php', null, self::starterBuilder()),
            new GenericProjectionEmitter('resource', 'resource_index.php', null, self::resourceBuilder()),
            new GenericProjectionEmitter('admin_resource', 'admin_resource_index.php', null, self::adminResourceBuilder()),
            new GenericProjectionEmitter('upload_profile', 'upload_profile_index.php', null, self::uploadProfileBuilder()),
            new GenericProjectionEmitter('listing_config', 'listing_index.php', null, self::listingBuilder()),
            new GenericProjectionEmitter('form_definition', 'form_index.php', null, self::formBuilder()),
        ];
    }

    /**
     * @return callable(ApplicationGraph):array<string,mixed>
     */
    private static function starterBuilder(): callable
    {
        return static function (ApplicationGraph $graph): array {
            $rows = [];
            foreach ($graph->nodesByType('starter_kit') as $node) {
                $payload = $node->payload();
                $name = (string) ($payload['starter'] ?? '');
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
    private static function resourceBuilder(): callable
    {
        return static function (ApplicationGraph $graph): array {
            $rows = [];
            foreach ($graph->nodesByType('resource') as $node) {
                $payload = $node->payload();
                $name = (string) ($payload['resource'] ?? '');
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
    private static function adminResourceBuilder(): callable
    {
        return static function (ApplicationGraph $graph): array {
            $rows = [];
            foreach ($graph->nodesByType('admin_resource') as $node) {
                $payload = $node->payload();
                $name = (string) ($payload['resource'] ?? '');
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
    private static function uploadProfileBuilder(): callable
    {
        return static function (ApplicationGraph $graph): array {
            $rows = [];
            foreach ($graph->nodesByType('upload_profile') as $node) {
                $payload = $node->payload();
                $name = (string) ($payload['profile'] ?? '');
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
    private static function listingBuilder(): callable
    {
        return static function (ApplicationGraph $graph): array {
            $rows = [];
            foreach ($graph->nodesByType('listing_config') as $node) {
                $payload = $node->payload();
                $name = (string) ($payload['resource'] ?? '');
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
    private static function formBuilder(): callable
    {
        return static function (ApplicationGraph $graph): array {
            $rows = [];
            foreach ($graph->nodesByType('form_definition') as $node) {
                $payload = $node->payload();
                $resource = (string) ($payload['resource'] ?? '');
                $intent = (string) ($payload['intent'] ?? '');
                if ($resource === '' || $intent === '') {
                    continue;
                }

                $rows[$resource . ':' . $intent] = $payload;
            }

            ksort($rows);

            return $rows;
        };
    }
}
