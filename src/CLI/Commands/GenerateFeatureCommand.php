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
    public function supportedSignatures(): array
    {
        return ['generate feature', 'generate tests', 'generate context'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'generate' && $this->supportsSignature('generate ' . (string) ($args[1] ?? ''));
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $target = (string) ($args[1] ?? '');

        if ($target === 'feature') {
            $definitionPath = (string) ($args[2] ?? '');
            if ($definitionPath === '') {
                throw new FoundryError('CLI_DEFINITION_REQUIRED', 'validation', [], 'Feature definition path required.');
            }

            $files = $context->featureGenerator()->generateFromDefinition($definitionPath);

            return [
                'status' => 0,
                'message' => 'Feature generated.',
                'payload' => ['files' => $files],
            ];
        }

        if ($target === 'tests') {
            $mode = strtolower((string) ($this->extractOption($args, '--mode') ?? 'basic'));
            if (in_array($mode, ['deep', 'resource', 'api', 'notification'], true) || in_array('--all-missing', $args, true)) {
                $result = in_array('--all-missing', $args, true)
                    ? $context->deepTestGenerator()->generateAllMissing($mode)
                    : $context->deepTestGenerator()->generateForTarget((string) ($args[2] ?? ''), $mode);

                return [
                    'status' => 0,
                    'message' => 'Deep tests generated.',
                    'payload' => $result,
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
            $required = array_values(array_map('strval', (array) ($manifest['tests']['required'] ?? ['contract', 'feature', 'auth'])));
            $files = $context->testGenerator()->generate($feature, $base, $required);

            return [
                'status' => 0,
                'message' => 'Tests generated.',
                'payload' => ['mode' => 'basic', 'target' => $feature, 'files' => $files],
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

        $path = $context->contextGenerator()->write($feature, $manifest);

        return [
            'status' => 0,
            'message' => 'Context manifest generated.',
            'payload' => ['file' => $path],
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function extractOption(array $args, string $name): ?string
    {
        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, $name . '=')) {
                $value = substr($arg, strlen($name . '='));

                return $value !== '' ? $value : null;
            }

            if ($arg === $name) {
                $value = (string) ($args[$index + 1] ?? '');

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }
}
