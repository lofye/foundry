<?php
declare(strict_types=1);

namespace Forge\Verification;

use Forge\Support\Paths;
use Forge\Support\Yaml;

final class CacheVerifier
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function verify(): VerificationResult
    {
        $errors = [];
        $allFeatures = array_map('basename', glob($this->paths->features() . '/*', GLOB_ONLYDIR) ?: []);

        foreach (glob($this->paths->features() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $feature = basename($dir);
            $cachePath = $dir . '/cache.yaml';
            if (!is_file($cachePath)) {
                continue;
            }

            $cache = Yaml::parseFile($cachePath);
            foreach ((array) ($cache['entries'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $key = (string) ($entry['key'] ?? '');
                if ($key === '' || !preg_match('/^[a-z0-9:_{}-]+$/', $key)) {
                    $errors[] = "{$feature}: invalid cache key {$key}";
                }

                foreach ((array) ($entry['invalidated_by'] ?? []) as $invalidator) {
                    if (!in_array((string) $invalidator, $allFeatures, true)) {
                        $errors[] = "{$feature}: invalid invalidation target {$invalidator}";
                    }
                }
            }
        }

        return new VerificationResult($errors === [], $errors);
    }
}
