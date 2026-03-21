<?php
declare(strict_types=1);

namespace Foundry\Pro\CLI;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\PromptCommand;
use Foundry\Pro\CLI\Concerns\InteractsWithPro;
use Foundry\Support\FoundryError;

final class GenerateCommand extends Command
{
    use InteractsWithPro;

    /**
     * @var array<int,string>
     */
    private const RESERVED_TARGETS = [
        'feature',
        'starter',
        'resource',
        'admin-resource',
        'uploads',
        'notification',
        'api-resource',
        'docs',
        'indexes',
        'tests',
        'migration',
        'context',
        'billing',
        'workflow',
        'orchestration',
        'search-index',
        'stream',
        'locale',
        'roles',
        'policy',
        'inspect-ui',
    ];

    #[\Override]
    public function matches(array $args): bool
    {
        if (($args[0] ?? null) !== 'generate') {
            return false;
        }

        $target = trim((string) ($args[1] ?? ''));
        if ($target === '' || str_starts_with($target, '--')) {
            return false;
        }

        return !in_array($target, self::RESERVED_TARGETS, true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $license = $this->requirePro('generate <prompt>', ['ai_assisted_generation']);
        $prompt = trim(implode(' ', array_slice($args, 1)));

        if ($prompt === '') {
            throw new FoundryError(
                'PRO_GENERATE_PROMPT_REQUIRED',
                'validation',
                [],
                'A generation prompt is required.',
            );
        }

        $base = (new PromptCommand())->run(
            array_merge(['prompt'], array_slice($args, 1)),
            new CommandContext($context->paths()->root(), true),
        );

        $payload = is_array($base['payload']) ? $base['payload'] : [];
        if ($payload !== [] && !array_key_exists('error', $payload)) {
            $payload['mode'] = 'pro_generate';
            $payload['pro'] = [
                'license' => $license,
                'workflow' => [
                    'prompt' => $prompt,
                    'next_step' => 'Use the generated prompt bundle to drive an AI-assisted implementation workflow.',
                ],
            ];
        }

        return [
            'status' => $base['status'],
            'message' => $context->expectsJson()
                ? null
                : 'Foundry Pro generation bundle prepared for: ' . $prompt,
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }
}
