<?php
declare(strict_types=1);

namespace Foundry\Compiler\Codemod;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class CodemodEngine
{
    /**
     * @var array<string,Codemod>
     */
    private array $codemods = [];

    /**
     * @param array<int,Codemod> $codemods
     */
    public function __construct(
        private readonly Paths $paths,
        array $codemods = [],
    ) {
        foreach ($codemods as $codemod) {
            $this->register($codemod);
        }
    }

    public function register(Codemod $codemod): void
    {
        $this->codemods[$codemod->id()] = $codemod;
        ksort($this->codemods);
    }

    public function has(string $id): bool
    {
        return isset($this->codemods[$id]);
    }

    public function run(string $id, bool $write = false, ?string $path = null): CodemodResult
    {
        $codemod = $this->codemods[$id] ?? null;
        if ($codemod === null) {
            throw new FoundryError(
                'FDY7101_CODEMOD_NOT_FOUND',
                'migrations',
                ['codemod' => $id],
                'Codemod not found.',
            );
        }

        return $codemod->run($this->paths, $write, $path);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function inspectRows(): array
    {
        $rows = [];
        foreach ($this->codemods as $codemod) {
            $rows[] = [
                'id' => $codemod->id(),
                'description' => $codemod->description(),
                'source_type' => $codemod->sourceType(),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $rows;
    }
}
