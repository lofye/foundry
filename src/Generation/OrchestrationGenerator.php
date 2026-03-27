<?php

declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class OrchestrationGenerator
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureGenerator $features,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function generate(string $name, string $definitionPath, bool $force = false): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new FoundryError('ORCHESTRATION_NAME_REQUIRED', 'validation', [], 'Orchestration name is required.');
        }

        $source = $this->resolvePath($definitionPath);
        if (!is_file($source)) {
            throw new FoundryError('ORCHESTRATION_DEFINITION_MISSING', 'not_found', ['definition' => $definitionPath], 'Orchestration definition file not found.');
        }

        $document = Yaml::parseFile($source);
        $dir = $this->paths->join('app/definitions/orchestrations');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $targetDefinition = $dir . '/' . $name . '.orchestration.yaml';
        if (is_file($targetDefinition) && !$force) {
            throw new FoundryError('ORCHESTRATION_DEFINITION_EXISTS', 'io', ['path' => $targetDefinition], 'Orchestration definition already exists. Use --force to overwrite.');
        }

        $normalized = [
            'version' => 1,
            'name' => (string) ($document['name'] ?? $name),
            'steps' => array_values((array) ($document['steps'] ?? [])),
        ];
        file_put_contents($targetDefinition, Yaml::dump($normalized));

        $feature = 'start_' . $name . '_orchestration';
        $files = $this->features->generateFromArray([
            'feature' => $feature,
            'description' => 'Start orchestration run for ' . $name . '.',
            'route' => ['method' => 'POST', 'path' => '/orchestrations/' . $name . '/start'],
            'input' => ['input' => ['type' => 'object', 'required' => false]],
            'output' => ['run_id' => ['type' => 'string', 'required' => true]],
            'auth' => ['required' => true, 'strategies' => ['session'], 'permissions' => ['orchestration.start']],
            'database' => ['reads' => ['workflow_runs'], 'writes' => ['workflow_runs', 'workflow_step_runs'], 'queries' => ['start_' . $name . '_orchestration']],
            'jobs' => ['dispatch' => ['run_' . $name . '_orchestration']],
            'tests' => ['required' => ['contract', 'feature', 'auth']],
        ], $force);

        $files[] = $targetDefinition;
        $files = array_values(array_unique($files));
        sort($files);

        return [
            'orchestration' => $name,
            'feature' => $feature,
            'definition' => $targetDefinition,
            'files' => $files,
        ];
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, $this->paths->root() . '/')
            ? $path
            : $this->paths->join($path);
    }
}
