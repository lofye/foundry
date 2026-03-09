<?php
declare(strict_types=1);

namespace Foundry\Compiler\Projection;

use Foundry\Compiler\ApplicationGraph;

interface ProjectionEmitter
{
    public function id(): string;

    public function fileName(): string;

    public function legacyFileName(): ?string;

    /**
     * @return array<string,mixed>
     */
    public function emit(ApplicationGraph $graph): array;
}
