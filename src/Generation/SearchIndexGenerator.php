<?php
declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class SearchIndexGenerator
{
    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function generate(string $name, string $specPath, bool $force = false): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new FoundryError('SEARCH_INDEX_NAME_REQUIRED', 'validation', [], 'Search index name is required.');
        }

        $source = $this->resolvePath($specPath);
        if (!is_file($source)) {
            throw new FoundryError('SEARCH_INDEX_SPEC_MISSING', 'not_found', ['spec' => $specPath], 'Search index spec file not found.');
        }

        $document = Yaml::parseFile($source);
        $dir = $this->paths->join('app/specs/search');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $targetSpec = $dir . '/' . $name . '.search.yaml';
        if (is_file($targetSpec) && !$force) {
            throw new FoundryError('SEARCH_INDEX_SPEC_EXISTS', 'io', ['path' => $targetSpec], 'Search index spec already exists. Use --force to overwrite.');
        }

        $normalized = [
            'version' => 1,
            'index' => (string) ($document['index'] ?? $name),
            'adapter' => (string) ($document['adapter'] ?? 'sql'),
            'resource' => (string) ($document['resource'] ?? $name),
            'source' => is_array($document['source'] ?? null) ? $document['source'] : new \stdClass(),
            'fields' => array_values(array_map('strval', (array) ($document['fields'] ?? []))),
            'filters' => array_values(array_map('strval', (array) ($document['filters'] ?? []))),
        ];
        file_put_contents($targetSpec, Yaml::dump($normalized));

        return [
            'index' => $name,
            'spec' => $targetSpec,
            'files' => [$targetSpec],
        ];
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, $this->paths->root() . '/')
            ? $path
            : $this->paths->join($path);
    }
}
