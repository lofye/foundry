<?php

declare(strict_types=1);

namespace Foundry\Workflow;

use Foundry\Support\FoundryError;

final class WorkflowEngine
{
    /**
     * @param array<string,mixed> $definition
     */
    public function __construct(private readonly array $definition) {}

    /**
     * @param array<int,string> $permissions
     */
    public function canTransition(string $currentState, string $transition, array $permissions = []): bool
    {
        $row = $this->transition($transition);
        if ($row === null) {
            return false;
        }

        $from = array_values(array_map('strval', (array) ($row['from'] ?? [])));
        if (!in_array($currentState, $from, true)) {
            return false;
        }

        $requiredPermission = (string) ($row['permission'] ?? '');
        if ($requiredPermission !== '' && !in_array($requiredPermission, $permissions, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int,string> $permissions
     * @return array<string,mixed>
     */
    public function apply(string $currentState, string $transition, array $permissions = []): array
    {
        $row = $this->transition($transition);
        if ($row === null) {
            throw new FoundryError('WORKFLOW_TRANSITION_UNKNOWN', 'workflows', ['transition' => $transition], 'Workflow transition not defined.');
        }

        if (!$this->canTransition($currentState, $transition, $permissions)) {
            throw new FoundryError('WORKFLOW_TRANSITION_DENIED', 'workflows', ['transition' => $transition, 'state' => $currentState], 'Workflow transition denied.');
        }

        return [
            'from' => $currentState,
            'to' => (string) ($row['to'] ?? $currentState),
            'transition' => $transition,
            'emit' => array_values(array_map('strval', (array) ($row['emit'] ?? []))),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function transition(string $transition): ?array
    {
        $transitions = is_array($this->definition['transitions'] ?? null) ? $this->definition['transitions'] : [];
        $row = $transitions[$transition] ?? null;

        return is_array($row) ? $row : null;
    }
}
