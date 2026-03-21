<?php
declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Explain\ExplainArtifactCatalog;
use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final readonly class EventContextCollector implements ExplainContextCollectorInterface
{
    public function __construct(private ExplainArtifactCatalog $artifacts)
    {
    }

    public function supports(ExplainSubject $subject): bool
    {
        return in_array($subject->kind, ['feature', 'route', 'event', 'workflow', 'job'], true);
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $index = $this->artifacts->eventIndex();
        $emit = is_array($index['emit'] ?? null) ? $index['emit'] : [];
        $subscribe = is_array($index['subscribe'] ?? null) ? $index['subscribe'] : [];

        $payload = [
            'emitted' => [],
            'subscribed' => [],
            'emitters' => [],
            'subscribers' => [],
            'event' => null,
        ];

        if (in_array($subject->kind, ['feature', 'route'], true)) {
            $feature = trim((string) ($subject->metadata['feature'] ?? $subject->label));
            foreach ($emit as $name => $row) {
                if (is_array($row) && (string) ($row['feature'] ?? '') === $feature) {
                    $payload['emitted'][(string) $name] = $row;
                }
            }

            foreach ($subscribe as $name => $features) {
                if (in_array($feature, array_values(array_map('strval', (array) $features)), true)) {
                    $payload['subscribed'][(string) $name] = array_values(array_map('strval', (array) $features));
                }
            }
        }

        if ($subject->kind === 'event') {
            $name = (string) ($subject->metadata['name'] ?? $subject->label);
            $payload['event'] = is_array($emit[$name] ?? null) ? $emit[$name] : null;
            $emitter = trim((string) (($payload['event']['feature'] ?? null)));
            if ($emitter !== '') {
                $payload['emitters'] = [$emitter];
            }
            $payload['subscribers'] = array_values(array_map('strval', (array) ($subscribe[$name] ?? [])));
        }

        $context->setEvents($payload);
    }
}
