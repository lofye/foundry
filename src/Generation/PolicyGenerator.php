<?php
declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Str;
use Foundry\Support\Yaml;

final class PolicyGenerator
{
    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function generate(string $name, bool $force = false): array
    {
        $policy = Str::toSnakeCase($name);
        if ($policy === '') {
            throw new FoundryError('POLICY_NAME_INVALID', 'validation', ['name' => $name], 'Policy name is invalid.');
        }

        $dir = $this->paths->join('app/specs/policies');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . '/' . $policy . '.policy.yaml';
        if (is_file($path) && !$force) {
            throw new FoundryError('POLICY_SPEC_EXISTS', 'io', ['path' => $path], 'Policy spec already exists. Use --force to overwrite.');
        }

        $spec = [
            'version' => 1,
            'policy' => $policy,
            'resource' => $policy,
            'rules' => [
                'admin' => ['*'],
                'editor' => [$policy . '.view', $policy . '.update'],
                'viewer' => [$policy . '.view'],
            ],
        ];
        file_put_contents($path, Yaml::dump($spec));

        return [
            'policy' => $policy,
            'spec' => $path,
            'files' => [$path],
        ];
    }
}
