<?php

declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class ApiResourceGenerator
{
    public function __construct(
        private readonly Paths $paths,
        private readonly ResourceGenerator $resourceGenerator,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function generate(string $name, string $definitionPath, bool $force = false): array
    {
        $definition = Yaml::parseFile($definitionPath);
        $resource = (string) ($definition['resource'] ?? $name);
        if ($resource === '') {
            throw new FoundryError('API_RESOURCE_NAME_INVALID', 'validation', ['name' => $name], 'API resource name is invalid.');
        }

        $style = (string) ($definition['style'] ?? 'api');
        if ($style !== 'api') {
            throw new FoundryError('API_RESOURCE_STYLE_INVALID', 'validation', ['style' => $style], 'API resource definition must set style: api.');
        }

        $generated = $this->resourceGenerator->generate($resource, $definitionPath, $force);
        $resourceDefinitionPath = (string) ($generated['definition'] ?? '');
        if ($resourceDefinitionPath === '' || !is_file($resourceDefinitionPath)) {
            throw new FoundryError('API_RESOURCE_DEFINITION_MISSING', 'io', ['resource' => $resource], 'Generated resource definition not found.');
        }

        $resourceDefinition = Yaml::parseFile($resourceDefinitionPath);
        $apiDir = $this->paths->join('app/definitions/api');
        if (!is_dir($apiDir)) {
            mkdir($apiDir, 0777, true);
        }

        $apiDefinitionPath = $apiDir . '/' . $resource . '.api-resource.yaml';
        if (is_file($apiDefinitionPath) && !$force) {
            throw new FoundryError('API_RESOURCE_DEFINITION_EXISTS', 'io', ['path' => $apiDefinitionPath], 'API resource definition already exists. Use --force to overwrite.');
        }

        $apiDefinition = $resourceDefinition;
        $apiDefinition['version'] = 1;
        $apiDefinition['style'] = 'api';

        file_put_contents($apiDefinitionPath, Yaml::dump($apiDefinition));

        $files = array_values(array_unique(array_merge(
            array_values(array_map('strval', (array) ($generated['files'] ?? []))),
            [$apiDefinitionPath],
        )));
        sort($files);

        return [
            'resource' => $resource,
            'style' => 'api',
            'features' => array_values(array_map('strval', (array) ($generated['features'] ?? []))),
            'files' => $files,
            'definition' => $apiDefinitionPath,
        ];
    }
}
