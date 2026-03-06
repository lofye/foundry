<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Support\FoundryError;
use Foundry\Support\Yaml;

final class GenerateFeatureCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'generate' && in_array(($args[1] ?? ''), ['feature', 'tests', 'context'], true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $target = (string) ($args[1] ?? '');

        if ($target === 'feature') {
            $specPath = (string) ($args[2] ?? '');
            if ($specPath === '') {
                throw new FoundryError('CLI_SPEC_REQUIRED', 'validation', [], 'Feature spec path required.');
            }

            $files = $context->featureGenerator()->generateFromSpec($specPath);

            return [
                'status' => 0,
                'message' => 'Feature generated.',
                'payload' => ['files' => $files],
            ];
        }

        $feature = (string) ($args[2] ?? '');
        if ($feature === '') {
            throw new FoundryError('CLI_FEATURE_REQUIRED', 'validation', [], 'Feature name required.');
        }

        $base = $context->paths()->join('app/features/' . $feature);
        $manifestPath = $base . '/feature.yaml';
        if (!is_file($manifestPath)) {
            throw new FoundryError('FEATURE_NOT_FOUND', 'not_found', ['feature' => $feature], 'Feature not found.');
        }

        $manifest = Yaml::parseFile($manifestPath);

        if ($target === 'tests') {
            $required = array_values(array_map('strval', (array) ($manifest['tests']['required'] ?? ['contract', 'feature', 'auth'])));
            $files = $context->testGenerator()->generate($feature, $base, $required);

            return [
                'status' => 0,
                'message' => 'Tests generated.',
                'payload' => ['files' => $files],
            ];
        }

        $path = $context->contextGenerator()->write($feature, $manifest);

        return [
            'status' => 0,
            'message' => 'Context manifest generated.',
            'payload' => ['file' => $path],
        ];
    }
}
