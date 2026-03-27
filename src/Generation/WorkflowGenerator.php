<?php

declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class WorkflowGenerator
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
            throw new FoundryError('WORKFLOW_NAME_REQUIRED', 'validation', [], 'Workflow name is required.');
        }

        $source = $this->resolvePath($definitionPath);
        if (!is_file($source)) {
            throw new FoundryError('WORKFLOW_DEFINITION_MISSING', 'not_found', ['definition' => $definitionPath], 'Workflow definition file not found.');
        }

        $document = Yaml::parseFile($source);
        $resource = (string) ($document['resource'] ?? $name);

        $dir = $this->paths->join('app/definitions/workflows');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $targetDefinition = $dir . '/' . $name . '.workflow.yaml';
        if (is_file($targetDefinition) && !$force) {
            throw new FoundryError('WORKFLOW_DEFINITION_EXISTS', 'io', ['path' => $targetDefinition], 'Workflow definition already exists. Use --force to overwrite.');
        }

        $normalized = [
            'version' => 1,
            'resource' => $resource,
            'states' => array_values(array_map('strval', (array) ($document['states'] ?? []))),
            'transitions' => is_array($document['transitions'] ?? null) ? $document['transitions'] : new \stdClass(),
        ];
        file_put_contents($targetDefinition, Yaml::dump($normalized));

        $feature = 'transition_' . $this->singularize($resource) . '_workflow';
        $files = $this->features->generateFromArray([
            'feature' => $feature,
            'description' => 'Execute workflow transitions for ' . $resource . '.',
            'route' => ['method' => 'POST', 'path' => '/' . $resource . '/{id}/transition'],
            'input' => [
                'id' => ['type' => 'string', 'required' => true],
                'transition' => ['type' => 'string', 'required' => true],
            ],
            'output' => [
                'status' => ['type' => 'string', 'required' => true],
            ],
            'auth' => ['required' => true, 'strategies' => ['session'], 'permissions' => [$resource . '.transition']],
            'database' => ['reads' => [$resource], 'writes' => [$resource], 'queries' => ['transition_' . $resource]],
            'events' => ['emit' => [$this->singularize($resource) . '.workflow.transitioned']],
            'tests' => ['required' => ['contract', 'feature', 'auth']],
        ], $force);

        $files[] = $targetDefinition;
        $files = array_values(array_unique($files));
        sort($files);

        return [
            'workflow' => $name,
            'resource' => $resource,
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

    private function singularize(string $value): string
    {
        if (str_ends_with($value, 'ies') && strlen($value) > 3) {
            return substr($value, 0, -3) . 'y';
        }

        if (str_ends_with($value, 's') && strlen($value) > 1) {
            return substr($value, 0, -1);
        }

        return $value;
    }
}
