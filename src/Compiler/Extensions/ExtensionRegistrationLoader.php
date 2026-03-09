<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class ExtensionRegistrationLoader
{
    /**
     * @return array{source_paths:array<int,string>,classes:array<int,string>}
     */
    public function load(Paths $paths): array
    {
        $sourcePaths = [];
        $classes = [];

        foreach ($this->registrationPaths($paths) as $path) {
            if (!is_file($path)) {
                continue;
            }

            $sourcePaths[] = $this->relativePath($paths, $path);

            /** @var mixed $payload */
            $payload = require $path;
            if (!is_array($payload)) {
                throw new FoundryError(
                    'FDY7010_EXTENSION_REGISTRATION_INVALID',
                    'extensions',
                    ['path' => $path],
                    'Extension registration file must return an array of extension class names.',
                );
            }

            foreach ($payload as $class) {
                $className = is_string($class) ? trim($class) : '';
                if ($className === '') {
                    continue;
                }
                $classes[] = $className;
            }
        }

        $classes = array_values(array_unique($classes));

        return [
            'source_paths' => $sourcePaths,
            'classes' => $classes,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function registrationPaths(Paths $paths): array
    {
        return [
            $paths->join('foundry.extensions.php'),
            $paths->join('app/platform/foundry/extensions.php'),
        ];
    }

    private function relativePath(Paths $paths, string $absolute): string
    {
        $root = rtrim($paths->root(), '/') . '/';

        return str_starts_with($absolute, $root)
            ? substr($absolute, strlen($root))
            : $absolute;
    }
}
