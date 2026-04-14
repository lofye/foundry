<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Context\ExecutionSpecDraftService;

final class SpecNewCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['spec:new'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'spec:new';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $feature = (string) ($args[1] ?? '');
        $slug = (string) ($args[2] ?? '');

        $payload = (new ExecutionSpecDraftService($context->paths()))
            ->createDraft($feature, $slug);

        return [
            'status' => $payload['success'] ? 0 : 1,
            'message' => $context->expectsJson() ? null : $this->renderMessage($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array{
     *     success:bool,
     *     feature:string,
     *     provided_feature:string,
     *     id:?string,
     *     slug:?string,
     *     provided_slug:string,
     *     path:?string,
     *     reason:?string,
     *     required_actions:list<string>
     * } $payload
     */
    private function renderMessage(array $payload): string
    {
        if ($payload['success']) {
            return implode(PHP_EOL, [
                'Created draft spec',
                '',
                'Feature: ' . $payload['feature'],
                'ID: ' . (string) $payload['id'],
                'Slug: ' . (string) $payload['slug'],
                'Path: ' . (string) $payload['path'],
                '',
                'Next steps:',
                ...array_map(
                    static fn(string $action): string => '- ' . $action,
                    $payload['required_actions'],
                ),
            ]);
        }

        $subjectLabel = in_array($payload['reason'], ['invalid feature name', 'could not allocate next spec ID'], true)
            ? 'Feature'
            : ($payload['reason'] === 'invalid slug' ? 'Slug' : 'Path');
        $subjectValue = match ($subjectLabel) {
            'Feature' => $payload['provided_feature'],
            'Slug' => $payload['provided_slug'],
            default => (string) ($payload['path'] ?? ''),
        };

        return implode(PHP_EOL, [
            'Could not create draft spec',
            '',
            'Reason: ' . (string) $payload['reason'],
            $subjectLabel . ': ' . $subjectValue,
            '',
            'Required action:',
            ...array_map(
                static fn(string $action): string => '- ' . $action,
                $payload['required_actions'],
            ),
        ]);
    }
}
