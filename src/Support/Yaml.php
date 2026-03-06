<?php
declare(strict_types=1);

namespace Forge\Support;

use Symfony\Component\Yaml\Yaml as SymfonyYaml;

final class Yaml
{
    /**
     * @return array<string,mixed>
     */
    public static function parseFile(string $path): array
    {
        if (!is_file($path)) {
            throw new ForgeError('YAML_FILE_NOT_FOUND', 'io', ['path' => $path], 'YAML file not found.');
        }

        try {
            /** @var mixed $data */
            $data = SymfonyYaml::parseFile($path);
        } catch (\Throwable $e) {
            throw new ForgeError('YAML_PARSE_ERROR', 'parsing', ['path' => $path], $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new ForgeError('YAML_OBJECT_REQUIRED', 'validation', ['path' => $path], 'YAML root must be a map.');
        }

        return $data;
    }

    public static function dump(array $data): string
    {
        return SymfonyYaml::dump($data, 6, 2, SymfonyYaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }
}
