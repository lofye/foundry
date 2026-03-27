<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Support\FoundryError;

final class InspectFeatureCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['inspect feature', 'inspect auth', 'inspect cache', 'inspect events', 'inspect jobs', 'inspect context'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'inspect' && $this->supportsSignature('inspect ' . (string) ($args[1] ?? ''));
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $kind = (string) ($args[1] ?? '');
        $featureName = (string) ($args[2] ?? '');
        if ($featureName === '') {
            throw new FoundryError('CLI_FEATURE_REQUIRED', 'validation', [], 'Feature name required.');
        }

        $loader = $context->featureLoader();
        $feature = $loader->get($featureName);
        $manifest = $loader->contextManifest($featureName);

        return match ($kind) {
            'feature' => [
                'status' => 0,
                'message' => null,
                'payload' => [
                    'feature' => $feature->name,
                    'kind' => $feature->kind,
                    'description' => $feature->description,
                    'route' => $feature->route,
                    'schemas' => [
                        'input' => $feature->inputSchemaPath,
                        'output' => $feature->outputSchemaPath,
                    ],
                    'auth' => $feature->auth,
                    'database' => $feature->database,
                    'cache' => $feature->cache,
                    'events' => $feature->events,
                    'jobs' => $feature->jobs,
                    'tests' => $feature->tests['required'] ?? [],
                    'context_manifest' => 'app/features/' . $featureName . '/context.manifest.json',
                    'relevant_files' => $manifest?->relevantFiles ?? [],
                ],
            ],
            'auth' => [
                'status' => 0,
                'message' => null,
                'payload' => ['feature' => $featureName, 'auth' => $feature->auth],
            ],
            'cache' => [
                'status' => 0,
                'message' => null,
                'payload' => ['feature' => $featureName, 'cache' => $feature->cache],
            ],
            'events' => [
                'status' => 0,
                'message' => null,
                'payload' => ['feature' => $featureName, 'events' => $feature->events],
            ],
            'jobs' => [
                'status' => 0,
                'message' => null,
                'payload' => ['feature' => $featureName, 'jobs' => $feature->jobs],
            ],
            'context' => [
                'status' => 0,
                'message' => null,
                'payload' => $manifest?->toArray() ?? ['feature' => $featureName, 'missing' => true],
            ],
            default => throw new FoundryError('CLI_INSPECT_KIND_INVALID', 'validation', ['kind' => $kind], 'Unsupported inspect target.'),
        };
    }
}
