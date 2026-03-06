<?php
declare(strict_types=1);

namespace Forge\CLI\Commands;

use Forge\CLI\Command;
use Forge\CLI\CommandContext;
use Forge\Support\ForgeError;
use Forge\Support\Yaml;

final class ImpactCommand extends Command
{
    public function matches(array $args): bool
    {
        return in_array(($args[0] ?? ''), ['affected-files', 'impacted-features'], true);
    }

    public function run(array $args, CommandContext $context): array
    {
        $command = (string) ($args[0] ?? '');

        if ($command === 'affected-files') {
            $feature = (string) ($args[1] ?? '');
            if ($feature === '') {
                throw new ForgeError('CLI_FEATURE_REQUIRED', 'validation', [], 'Feature required.');
            }

            $manifest = $context->featureLoader()->contextManifest($feature);
            if ($manifest === null) {
                throw new ForgeError('CONTEXT_MANIFEST_NOT_FOUND', 'not_found', ['feature' => $feature], 'Context manifest not found.');
            }

            return [
                'status' => 0,
                'message' => null,
                'payload' => [
                    'feature' => $feature,
                    'affected_files' => array_values(array_unique(array_merge($manifest->relevantFiles, $manifest->generatedFiles))),
                ],
            ];
        }

        $needle = (string) ($args[1] ?? '');
        if ($needle === '') {
            throw new ForgeError('CLI_IMPACT_TARGET_REQUIRED', 'validation', [], 'Impact target required.');
        }

        $features = [];
        foreach (glob($context->paths()->features() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $manifestPath = $dir . '/feature.yaml';
            if (!is_file($manifestPath)) {
                continue;
            }

            $manifest = Yaml::parseFile($manifestPath);
            $feature = basename($dir);

            if (str_starts_with($needle, 'event:')) {
                $event = substr($needle, strlen('event:'));
                if (in_array($event, (array) ($manifest['events']['emit'] ?? []), true) || in_array($event, (array) ($manifest['events']['subscribe'] ?? []), true)) {
                    $features[] = $feature;
                }
            } elseif (str_starts_with($needle, 'cache:')) {
                $cacheKey = substr($needle, strlen('cache:'));
                if (in_array($cacheKey, (array) ($manifest['cache']['invalidate'] ?? []), true)) {
                    $features[] = $feature;
                }
            } else {
                if (in_array($needle, (array) ($manifest['auth']['permissions'] ?? []), true)) {
                    $features[] = $feature;
                }
            }
        }

        sort($features);

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'target' => $needle,
                'features' => $features,
            ],
        ];
    }
}
