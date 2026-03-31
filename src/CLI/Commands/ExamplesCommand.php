<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Examples\ExampleLoader;
use Foundry\Support\FoundryError;
use Foundry\UX\FirstRunService;

final class ExamplesCommand extends Command
{
    public function __construct(
        private readonly ?ExampleLoader $loader = null,
        private readonly ?FirstRunService $firstRun = null,
    ) {}

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['examples:list', 'examples:load'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return in_array((string) ($args[0] ?? ''), ['examples:list', 'examples:load'], true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $command = (string) ($args[0] ?? '');

        return match ($command) {
            'examples:list' => $this->listExamples($context),
            'examples:load' => $this->loadExample($args, $context),
            default => throw new FoundryError('CLI_EXAMPLES_COMMAND_INVALID', 'validation', ['command' => $command], 'Unsupported examples command.'),
        };
    }

    /**
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    private function listExamples(CommandContext $context): array
    {
        $examples = ($this->loader ?? new ExampleLoader($context->paths()))->available();
        $payload = ['examples' => $examples];

        return [
            'status' => 0,
            'payload' => $context->expectsJson() ? $payload : null,
            'message' => $context->expectsJson() ? null : $this->renderList($examples),
        ];
    }

    /**
     * @param array<int,string> $args
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    private function loadExample(array $args, CommandContext $context): array
    {
        $name = trim((string) ($args[1] ?? ''));
        if ($name === '') {
            throw new FoundryError(
                'CLI_EXAMPLE_NAME_REQUIRED',
                'validation',
                [],
                'Example name required. Usage: examples:load <blog-api|extensions-migrations> [--temp]',
            );
        }

        return ($this->firstRun ?? new FirstRunService($this->loader ?? new ExampleLoader($context->paths())))
            ->loadExampleFlow($context, $name, in_array('--temp', $args, true), 'examples:load');
    }

    /**
     * @param array<int,array<string,mixed>> $examples
     */
    private function renderList(array $examples): string
    {
        $lines = ['Available examples:'];

        foreach ($examples as $example) {
            if (!is_array($example)) {
                continue;
            }

            $lines[] = '- '
                . (string) ($example['name'] ?? '')
                . ' ['
                . (string) ($example['taxonomy'] ?? 'reference')
                . ' / '
                . (string) ($example['mode'] ?? 'direct_copy')
                . ']: '
                . (string) ($example['label'] ?? '');
            $lines[] = '  '
                . (string) ($example['description'] ?? '')
                . ' Sources: '
                . implode(', ', array_values(array_map('strval', (array) ($example['source_examples'] ?? []))));
        }

        return implode(PHP_EOL, $lines);
    }
}
