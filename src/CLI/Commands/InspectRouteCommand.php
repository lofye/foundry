<?php
declare(strict_types=1);

namespace Forge\CLI\Commands;

use Forge\CLI\Command;
use Forge\CLI\CommandContext;
use Forge\Support\ForgeError;

final class InspectRouteCommand extends Command
{
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'inspect' && ($args[1] ?? null) === 'route';
    }

    public function run(array $args, CommandContext $context): array
    {
        $method = strtoupper((string) ($args[2] ?? ''));
        $path = (string) ($args[3] ?? '');
        if ($method === '' || $path === '') {
            throw new ForgeError('CLI_ROUTE_REQUIRED', 'validation', [], 'Method and path required.');
        }

        foreach ($context->featureLoader()->routes()->all() as $route) {
            if (strtoupper($route->method) === $method && $route->path === $path) {
                return [
                    'status' => 0,
                    'message' => null,
                    'payload' => [
                        'route' => $method . ' ' . $path,
                        'feature' => $route->feature,
                        'kind' => $route->kind,
                        'input_schema' => $route->inputSchema,
                        'output_schema' => $route->outputSchema,
                    ],
                ];
            }
        }

        throw new ForgeError('ROUTE_NOT_FOUND', 'not_found', ['route' => $method . ' ' . $path], 'Route not found.');
    }
}
