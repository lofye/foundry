<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

final readonly class CompatibilityReport
{
    /**
     * @param array<int,array<string,mixed>> $diagnostics
     * @param array<string,mixed> $versionMatrix
     */
    public function __construct(
        public bool $ok,
        public array $diagnostics,
        public array $versionMatrix,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'diagnostics' => $this->diagnostics,
            'version_matrix' => $this->versionMatrix,
        ];
    }
}
