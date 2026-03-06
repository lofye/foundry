<?php
declare(strict_types=1);

namespace Foundry\Observability;

use Foundry\Support\Clock;

final class StructuredLogger implements Logger
{
    /**
     * @var array<int,array<string,mixed>>
     */
    private array $records = [];

    public function __construct(private readonly Clock $clock = new Clock())
    {
    }

    #[\Override]
    public function log(string $level, string $message, array $context = []): void
    {
        $this->records[] = [
            'timestamp' => $this->clock->nowIso8601(),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function records(): array
    {
        return $this->records;
    }
}
