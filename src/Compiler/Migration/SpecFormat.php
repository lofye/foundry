<?php
declare(strict_types=1);

namespace Foundry\Compiler\Migration;

final class SpecFormat
{
    /**
     * @param array<int,int> $supportedVersions
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly int $currentVersion,
        public readonly array $supportedVersions,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $supported = array_values(array_unique(array_map('intval', $this->supportedVersions)));
        sort($supported);

        return [
            'name' => $this->name,
            'description' => $this->description,
            'current_version' => $this->currentVersion,
            'supported_versions' => $supported,
        ];
    }
}
