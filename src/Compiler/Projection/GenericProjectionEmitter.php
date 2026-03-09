<?php
declare(strict_types=1);

namespace Foundry\Compiler\Projection;

use Foundry\Compiler\ApplicationGraph;

final class GenericProjectionEmitter implements ProjectionEmitter
{
    /**
     * @param callable(ApplicationGraph):array<string,mixed> $builder
     */
    public function __construct(
        private readonly string $id,
        private readonly string $fileName,
        private readonly ?string $legacyFileName,
        callable $builder,
    ) {
        $this->builder = \Closure::fromCallable($builder);
    }

    private readonly \Closure $builder;

    public function id(): string
    {
        return $this->id;
    }

    public function fileName(): string
    {
        return $this->fileName;
    }

    public function legacyFileName(): ?string
    {
        return $this->legacyFileName;
    }

    /**
     * @return array<string,mixed>
     */
    public function emit(ApplicationGraph $graph): array
    {
        $builder = $this->builder;

        return $builder($graph);
    }
}
