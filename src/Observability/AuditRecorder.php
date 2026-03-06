<?php
declare(strict_types=1);

namespace Forge\Observability;

use Forge\Support\Clock;

final class AuditRecorder
{
    /**
     * @var array<int,array<string,mixed>>
     */
    private array $events = [];

    public function __construct(private readonly Clock $clock = new Clock())
    {
    }

    /**
     * @param array<string,mixed> $context
     */
    public function record(string $feature, string $action, array $context = []): void
    {
        $this->events[] = [
            'timestamp' => $this->clock->nowIso8601(),
            'feature' => $feature,
            'action' => $action,
            'context' => $context,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function events(): array
    {
        return $this->events;
    }
}
