<?php
declare(strict_types=1);

namespace Foundry\Compiler;

final readonly class CompilePlan
{
    /**
     * @param array<int,string> $selectedFeatures
     * @param array<int,string> $changedFeatures
     * @param array<int,string> $changedFiles
     */
    public function __construct(
        public string $mode,
        public bool $incremental,
        public bool $noChanges,
        public bool $fallbackToFull,
        public array $selectedFeatures,
        public array $changedFeatures,
        public array $changedFiles,
        public string $reason,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'incremental' => $this->incremental,
            'no_changes' => $this->noChanges,
            'fallback_to_full' => $this->fallbackToFull,
            'selected_features' => $this->selectedFeatures,
            'changed_features' => $this->changedFeatures,
            'changed_files' => $this->changedFiles,
            'reason' => $this->reason,
        ];
    }
}
