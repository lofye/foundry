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
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function generate(string $name, string $specPath, bool $force = false): array
    {
        $spec = Yaml::parseFile($specPath);
        $resource = (string) ($spec['resource'] ?? $name);
        if ($resource === '') {
            throw new FoundryError('API_RESOURCE_NAME_INVALID', 'validation', ['name' => $name], 'API resource name is invalid.');
        }

        $style = (string) ($spec['style'] ?? 'api');
        if ($style !== 'api') {
            throw new FoundryError('API_RESOURCE_STYLE_INVALID', 'validation', ['style' => $style], 'API resource spec must set style: api.');
        }

        $generated = $this->resourceGenerator->generate($resource, $specPath, $force);
        $resourceSpecPath = (string) ($generated['spec'] ?? '');
        if ($resourceSpecPath === '' || !is_file($resourceSpecPath)) {
            throw new FoundryError('API_RESOURCE_SPEC_MISSING', 'io', ['resource' => $resource], 'Generated resource spec not found.');
        }

        $resourceSpec = Yaml::parseFile($resourceSpecPath);
        $apiDir = $this->paths->join('app/specs/api');
        if (!is_dir($apiDir)) {
            mkdir($apiDir, 0777, true);
        }

        $apiSpecPath = $apiDir . '/' . $resource . '.api-resource.yaml';
        if (is_file($apiSpecPath) && !$force) {
            throw new FoundryError('API_RESOURCE_SPEC_EXISTS', 'io', ['path' => $apiSpecPath], 'API resource spec already exists. Use --force to overwrite.');
        }

        $apiSpec = $resourceSpec;
        $apiSpec['version'] = 1;
        $apiSpec['style'] = 'api';

        file_put_contents($apiSpecPath, Yaml::dump($apiSpec));

        $files = array_values(array_unique(array_merge(
            array_values(array_map('strval', (array) ($generated['files'] ?? []))),
            [$apiSpecPath],
        )));
        sort($files);

        return [
            'resource' => $resource,
            'style' => 'api',
            'features' => array_values(array_map('strval', (array) ($generated['features'] ?? []))),
            'files' => $files,
            'spec' => $apiSpecPath,
        ];
    }
}
