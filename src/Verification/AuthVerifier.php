<?php
declare(strict_types=1);

namespace Forge\Verification;

use Forge\Support\Paths;
use Forge\Support\Yaml;

final class AuthVerifier
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function verify(): VerificationResult
    {
        $errors = [];
        $dirs = glob($this->paths->features() . '/*', GLOB_ONLYDIR) ?: [];

        foreach ($dirs as $dir) {
            $feature = basename($dir);
            $manifest = Yaml::parseFile($dir . '/feature.yaml');
            if (($manifest['kind'] ?? '') !== 'http') {
                continue;
            }

            if (!isset($manifest['auth']) || !is_array($manifest['auth'])) {
                $errors[] = "{$feature}: HTTP feature must declare auth section.";
                continue;
            }

            $permissions = array_values(array_map('strval', (array) ($manifest['auth']['permissions'] ?? [])));
            $permissionsFile = $dir . '/permissions.yaml';
            $known = [];
            if (is_file($permissionsFile)) {
                $loaded = Yaml::parseFile($permissionsFile);
                $known = array_values(array_map('strval', (array) ($loaded['permissions'] ?? [])));
            }

            foreach ($permissions as $permission) {
                if (!in_array($permission, $known, true)) {
                    $errors[] = "{$feature}: referenced permission {$permission} not declared in permissions.yaml.";
                }
            }

            $writes = array_values(array_map('strval', (array) ($manifest['database']['writes'] ?? [])));
            $authRequired = (bool) ($manifest['auth']['required'] ?? false);
            $isPublic = (bool) ($manifest['auth']['public'] ?? false);
            if ($writes !== [] && !$authRequired && !$isPublic) {
                $errors[] = "{$feature}: write feature is unguarded (set auth.required=true or auth.public=true).";
            }
        }

        return new VerificationResult($errors === [], $errors);
    }
}
