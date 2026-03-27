<?php
declare(strict_types=1);

namespace Foundry\Upgrade;

final readonly class DeprecationMetadata
{
    public function __construct(
        public string $id,
        public string $title,
        public string $severity,
        public string $category,
        public string $introducedIn,
        public string $removalVersion,
        public string $whyItMatters,
        public string $migration,
        public string $reference,
    ) {
    }

    public function appliesTo(string $targetVersion): bool
    {
        return VersionComparator::compare($targetVersion, $this->removalVersion) >= 0;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'severity' => $this->severity,
            'category' => $this->category,
            'introduced_in' => $this->introducedIn,
            'removal_version' => $this->removalVersion,
            'why_it_matters' => $this->whyItMatters,
            'migration' => $this->migration,
            'reference' => $this->reference,
        ];
    }
}
