<?php

declare(strict_types=1);

namespace Foundry\Pro\CLI;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Pro\CLI\Concerns\InteractsWithPro;
use Foundry\Pro\Generation\AIGenerationService;
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
    public function supportedSignatures(): array
    {
        return ['generate <prompt>'];
    }

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
        [$prompt, $options] = $this->parse($args);

        if ($prompt === '') {
            throw new FoundryError(
                'PRO_GENERATE_PROMPT_REQUIRED',
                'validation',
                [],
                'A generation prompt is required.',
            );
        }

        $result = (new AIGenerationService(
            $context->paths(),
            $context->graphCompiler(),
            $context->graphVerifier(),
            $context->featureGenerator(),
            $context->workflowGenerator(),
            $context->contractsVerifier(),
            $context->workflowVerifier(),
        ))->generate($prompt, $options);

        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $payload['mode'] = 'pro_generate';
        $payload['pro'] = ['license' => $license];

        return [
            'status' => (int) ($result['status'] ?? 0),
            'message' => $context->expectsJson() ? null : $this->renderMessage($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array<int,string> $args
     * @return array{0:string,1:array<string,mixed>}
     */
    private function parse(array $args): array
    {
        $parts = [];
        $options = [
            'feature_context' => false,
            'dry_run' => false,
            'deterministic' => false,
            'force' => false,
            'provider' => null,
            'model' => null,
        ];

        $skipNext = false;
        foreach ($args as $index => $arg) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }

            if ($index === 0) {
                continue;
            }

            if ($arg === '--feature-context') {
                $options['feature_context'] = true;
                continue;
            }

            if ($arg === '--dry-run') {
                $options['dry_run'] = true;
                continue;
            }

            if ($arg === '--deterministic') {
                $options['deterministic'] = true;
                continue;
            }

            if ($arg === '--force') {
                $options['force'] = true;
                continue;
            }

            if (str_starts_with($arg, '--provider=')) {
                $options['provider'] = substr($arg, strlen('--provider='));
                continue;
            }

            if ($arg === '--provider') {
                $options['provider'] = (string) ($args[$index + 1] ?? '');
                $skipNext = true;
                continue;
            }

            if (str_starts_with($arg, '--model=')) {
                $options['model'] = substr($arg, strlen('--model='));
                continue;
            }

            if ($arg === '--model') {
                $options['model'] = (string) ($args[$index + 1] ?? '');
                $skipNext = true;
                continue;
            }

            if (str_starts_with($arg, '--')) {
                continue;
            }

            $parts[] = $arg;
        }

        return [trim(implode(' ', $parts)), $options];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderMessage(array $payload): string
    {
        $feature = trim((string) ($payload['plan']['feature']['feature'] ?? 'generated_feature'));
        if (($payload['dry_run'] ?? false) === true) {
            return 'Foundry Pro generation plan prepared for ' . $feature . '.';
        }

        $files = count((array) ($payload['generated']['files'] ?? []));
        $providerMode = (string) ($payload['provider']['mode'] ?? 'provider');

        return sprintf(
            'Foundry Pro generated %s using %s mode and wrote %d file(s).',
            $feature,
            $providerMode,
            $files,
        );
    }
}
