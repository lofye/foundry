<?php
declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Str;
use Foundry\Support\Yaml;

final class StreamGenerator
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureGenerator $features,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function generate(string $name, bool $force = false): array
    {
        $stream = Str::toSnakeCase($name);
        if ($stream === '') {
            throw new FoundryError('STREAM_NAME_INVALID', 'validation', ['name' => $name], 'Stream name is invalid.');
        }

        $dir = $this->paths->join('app/specs/streams');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $specPath = $dir . '/' . $stream . '.stream.yaml';
        if (is_file($specPath) && !$force) {
            throw new FoundryError('STREAM_SPEC_EXISTS', 'io', ['path' => $specPath], 'Stream spec already exists. Use --force to overwrite.');
        }

        $spec = [
            'version' => 1,
            'stream' => $stream,
            'transport' => 'sse',
            'route' => ['path' => '/streams/' . $stream],
            'auth' => ['required' => true, 'strategies' => ['session']],
            'publish_features' => [],
            'payload_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'event' => ['type' => 'string'],
                    'data' => ['type' => 'object'],
                ],
            ],
        ];
        file_put_contents($specPath, Yaml::dump($spec));

        $feature = 'stream_' . $stream;
        $files = $this->features->generateFromArray([
            'feature' => $feature,
            'description' => 'Expose SSE stream ' . $stream . '.',
            'route' => ['method' => 'GET', 'path' => '/streams/' . $stream],
            'input' => [],
            'output' => [
                'stream' => ['type' => 'string', 'required' => true],
                'events' => ['type' => 'array', 'required' => true],
            ],
            'auth' => ['required' => true, 'strategies' => ['session'], 'permissions' => ['streams.view']],
            'database' => ['reads' => [], 'writes' => [], 'queries' => ['stream_' . $stream]],
            'tests' => ['required' => ['contract', 'feature', 'auth']],
        ], $force);

        $files[] = $specPath;
        $files = array_values(array_unique($files));
        sort($files);

        return [
            'stream' => $stream,
            'feature' => $feature,
            'spec' => $specPath,
            'files' => $files,
        ];
    }
}
