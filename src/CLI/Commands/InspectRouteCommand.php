<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Support\FoundryError;

final class InspectRouteCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['inspect route'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'inspect' && ($args[1] ?? null) === 'route';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $method = strtoupper((string) ($args[2] ?? ''));
        $path = (string) ($args[3] ?? '');
        if ($method === '' || $path === '') {
            throw new FoundryError('CLI_ROUTE_REQUIRED', 'validation', [], 'Method and path required.');
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

        throw new FoundryError('ROUTE_NOT_FOUND', 'not_found', ['route' => $method . ' ' . $path], 'Route not found.');
    }
}
