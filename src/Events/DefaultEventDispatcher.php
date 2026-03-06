<?php
declare(strict_types=1);

namespace Forge\Events;

use Forge\Observability\TraceRecorder;
use Forge\Schema\JsonSchemaValidator;
use Forge\Support\ForgeError;

final class DefaultEventDispatcher implements EventDispatcher
{
    public function __construct(
        private readonly EventRegistry $events,
        private readonly ?TraceRecorder $traceRecorder = null,
    ) {
    }

    public function emit(string $eventName, array $payload): void
    {
        $event = $this->events->event($eventName);

        $tmpFile = tempnam(sys_get_temp_dir(), 'forge-event-schema-');
        if ($tmpFile === false) {
            throw new ForgeError('EVENT_SCHEMA_TEMPFILE_FAILED', 'runtime', ['event' => $eventName], 'Failed to create schema tempfile.');
        }

        file_put_contents($tmpFile, json_encode($event->schema, JSON_UNESCAPED_SLASHES));
        $validator = new JsonSchemaValidator();
        $validation = $validator->validate($payload, $tmpFile);
        @unlink($tmpFile);

        if (!$validation->isValid) {
            throw new ForgeError('EVENT_PAYLOAD_INVALID', 'validation', ['event' => $eventName], 'Event payload does not match schema.');
        }

        foreach ($this->events->subscribers($eventName) as $subscriber) {
            $subscriber->handle($payload);
        }

        $this->traceRecorder?->record($eventName, 'events', 'event_emit', ['subscriber_count' => count($this->events->subscribers($eventName))]);
    }
}
