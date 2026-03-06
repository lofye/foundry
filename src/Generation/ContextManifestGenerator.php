<?php
declare(strict_types=1);

namespace Forge\Generation;

use Forge\Support\Json;
use Forge\Support\Paths;

final class ContextManifestGenerator
{
    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public function build(string $feature, array $manifest): array
    {
        $featureDir = 'app/features/' . $feature;

        $relevant = [
            $featureDir . '/feature.yaml',
            $featureDir . '/action.php',
            $featureDir . '/input.schema.json',
            $featureDir . '/output.schema.json',
            $featureDir . '/queries.sql',
            $featureDir . '/permissions.yaml',
            $featureDir . '/cache.yaml',
            $featureDir . '/events.yaml',
            $featureDir . '/jobs.yaml',
            $featureDir . '/tests/' . $feature . '_contract_test.php',
            $featureDir . '/tests/' . $feature . '_feature_test.php',
            $featureDir . '/tests/' . $feature . '_auth_test.php',
        ];

        $relevant = array_values(array_filter($relevant, fn (string $path): bool => is_file($this->paths->join($path))));

        $generated = [
            'app/generated/routes.php',
            'app/generated/schema_index.php',
            'app/generated/feature_index.php',
            'app/generated/permission_index.php',
            'app/generated/event_index.php',
            'app/generated/job_index.php',
            'app/generated/cache_index.php',
            'app/generated/scheduler_index.php',
            'app/generated/webhook_index.php',
        ];

        $deps = array_values(array_unique(array_merge(
            ['auth'],
            (array) (($manifest['database']['queries'] ?? []) !== [] ? ['db'] : []),
            (array) (($manifest['jobs']['dispatch'] ?? []) !== [] ? ['queue'] : []),
            (array) (($manifest['events']['emit'] ?? []) !== [] ? ['events'] : []),
            (array) (($manifest['cache']['invalidate'] ?? []) !== [] ? ['cache'] : [])
        )));

        $data = [
            'version' => 1,
            'feature' => $feature,
            'kind' => (string) ($manifest['kind'] ?? 'http'),
            'relevant_files' => $relevant,
            'generated_files' => $generated,
            'upstream_dependencies' => $deps,
            'downstream_dependents' => array_values(array_map('strval', (array) (($manifest['jobs']['dispatch'] ?? [])))),
            'contracts' => [
                'input' => $featureDir . '/input.schema.json',
                'output' => $featureDir . '/output.schema.json',
            ],
            'tests' => array_values(array_map(static fn (string $name): string => $feature . '_' . $name . '_test', (array) ($manifest['tests']['required'] ?? []))),
            'forbidden_paths' => ['src/Core', 'src/Http'],
            'risk_level' => (string) (($manifest['llm']['risk'] ?? 'medium')),
        ];

        return $data;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public function write(string $feature, array $manifest): string
    {
        $path = $this->paths->join('app/features/' . $feature . '/context.manifest.json');
        file_put_contents($path, Json::encode($this->build($feature, $manifest), true) . "\n");

        return $path;
    }
}
