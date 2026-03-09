<?php
declare(strict_types=1);

namespace Foundry\Compiler\Migration;

final readonly class ManifestVersionResolver
{
    public function __construct(private int $currentFeatureVersion = 2)
    {
    }

    public function currentFeatureVersion(): int
    {
        return $this->currentFeatureVersion;
    }

    /**
     * @param array<string,mixed> $document
     */
    public function resolveFeatureVersion(array $document): int
    {
        $version = $document['version'] ?? 1;
        if (is_int($version)) {
            return $version;
        }

        if (is_numeric($version)) {
            return (int) $version;
        }

        return 1;
    }

    /**
     * @param array<string,mixed> $document
     */
    public function isFeatureOutdated(array $document): bool
    {
        return $this->resolveFeatureVersion($document) < $this->currentFeatureVersion;
    }
}
