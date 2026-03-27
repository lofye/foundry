<?php

declare(strict_types=1);

namespace Foundry\Schema;

interface SchemaValidator
{
    /**
     * @param array<string,mixed> $data
     */
    public function validate(array $data, string $schemaPath): ValidationResult;
}
