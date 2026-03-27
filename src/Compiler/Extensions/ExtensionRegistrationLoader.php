<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Support\Paths;

final class ExtensionRegistrationLoader
{
    /**
     * @return array{
     *   source_paths:array<int,string>,
     *   entries:array<int,array{class:string,source_path:string}>,
     *   diagnostics:array<int,array<string,mixed>>
     * }
     */
    public function load(Paths $paths): array
    {
        $sourcePaths = [];
        $entries = [];
        $diagnostics = [];

        foreach ($this->registrationPaths($paths) as $path) {
            if (!is_file($path)) {
                continue;
            }

            $relativePath = $this->relativePath($paths, $path);
            $sourcePaths[] = $relativePath;

            /** @var mixed $payload */
            $payload = require $path;
            if (!is_array($payload)) {
                $diagnostics[] = $this->diagnostic(
                    code: 'FDY7010_EXTENSION_REGISTRATION_INVALID',
                    message: 'Extension registration file must return an array of extension class names.',
                    sourcePath: $relativePath,
                    details: ['path' => $path],
                );
                continue;
            }

            foreach ($payload as $class) {
                $className = is_string($class) ? trim($class) : '';
                if ($className === '') {
                    continue;
                }

                $entries[] = [
                    'class' => $className,
                    'source_path' => $relativePath,
                ];
            }
        }

        $sourcePaths = array_values(array_unique($sourcePaths));
        sort($sourcePaths);

        return [
            'source_paths' => $sourcePaths,
            'entries' => $entries,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function registrationPaths(Paths $paths): array
    {
        return [
            $paths->join('foundry.extensions.php'),
            $paths->join('config/foundry/extensions.php'),
        ];
    }

    private function relativePath(Paths $paths, string $absolute): string
    {
        $root = rtrim($paths->root(), '/') . '/';

        return str_starts_with($absolute, $root)
            ? substr($absolute, strlen($root))
            : $absolute;
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private function diagnostic(string $code, string $message, string $sourcePath, array $details = []): array
    {
        return [
            'code' => $code,
            'severity' => 'error',
            'category' => 'extensions',
            'message' => $message,
            'source_path' => $sourcePath,
            'details' => $details,
        ];
    }
}
