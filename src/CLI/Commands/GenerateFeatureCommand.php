<?php
declare(strict_types=1);

namespace Forge\CLI\Commands;

use Forge\CLI\Command;
use Forge\CLI\CommandContext;
use Forge\Support\ForgeError;
use Forge\Support\Yaml;

final class GenerateFeatureCommand extends Command
{
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'generate' && in_array(($args[1] ?? ''), ['feature', 'tests', 'context'], true);
    }

    public function run(array $args, CommandContext $context): array
    {
        $target = (string) ($args[1] ?? '');

        if ($target === 'feature') {
            $specPath = (string) ($args[2] ?? '');
            if ($specPath === '') {
                throw new ForgeError('CLI_SPEC_REQUIRED', 'validation', [], 'Feature spec path required.');
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
            throw new ForgeError('CLI_FEATURE_REQUIRED', 'validation', [], 'Feature name required.');
        }

        $base = $context->paths()->join('app/features/' . $feature);
        $manifestPath = $base . '/feature.yaml';
        if (!is_file($manifestPath)) {
            throw new ForgeError('FEATURE_NOT_FOUND', 'not_found', ['feature' => $feature], 'Feature not found.');
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
