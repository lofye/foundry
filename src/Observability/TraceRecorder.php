<?php
declare(strict_types=1);

namespace Forge\Observability;

use Forge\Support\Clock;

final class TraceRecorder
{
    /**
     * @var array<int,array<string,mixed>>
     */
    private array $events = [];

    public function __construct(
        private readonly TraceContext $traceContext,
        private readonly Clock $clock = new Clock(),
    ) {
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function record(string $feature, string $component, string $action, array $metadata = [], ?float $durationMs = null): void
    {
        $event = [
            'timestamp' => $this->clock->nowIso8601(),
            'trace_id' => $this->traceContext->traceId(),
            'span_id' => $this->traceContext->newSpanId(),
            'feature' => $feature,
            'component' => $component,
            'action' => $action,
            'metadata' => $metadata,
        ];

        if ($durationMs !== null) {
            $event['duration_ms'] = $durationMs;
        }

        $this->events[] = $event;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function events(): array
    {
        return $this->events;
    }
}
