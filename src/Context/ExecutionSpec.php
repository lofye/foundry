<?php

declare(strict_types=1);

namespace Foundry\Context;

final readonly class ExecutionSpec
{
    /**
     * @param list<string> $scope
     * @param list<string> $constraints
     * @param list<string> $requestedChanges
     */
    public function __construct(
        public string $specId,
        public string $feature,
        public string $path,
        public string $purpose = '',
        public array $scope = [],
        public array $constraints = [],
        public array $requestedChanges = [],
        public string $name = '',
        public string $id = '',
        public ?string $parentId = null,
    ) {}

    /**
     * @return list<string>
     */
    public function instructionItems(): array
    {
        return array_values(array_merge($this->scope, $this->constraints, $this->requestedChanges));
    }
}
