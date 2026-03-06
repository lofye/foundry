<?php
declare(strict_types=1);

namespace Forge\Events;

final class EventDefinition
{
    /**
     * @param array<string,mixed> $schema
     */
    public function __construct(
        public readonly string $name,
        public readonly array $schema,
    ) {
    }
}
