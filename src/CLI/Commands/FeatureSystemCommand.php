<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\FeatureSystem\FeatureWorkspaceService;
use Foundry\Support\FoundryError;

final class FeatureSystemCommand extends Command
{
    public function __construct(private readonly ?FeatureWorkspaceService $service = null) {}

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['feature:list', 'feature:inspect', 'feature:map'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return in_array((string) ($args[0] ?? ''), ['feature:list', 'feature:inspect', 'feature:map'], true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $command = (string) ($args[0] ?? '');
        $service = $this->service ?? new FeatureWorkspaceService($context->paths());

        return match ($command) {
            'feature:list' => [
                'status' => 0,
                'message' => null,
                'payload' => $service->list(),
            ],
            'feature:inspect' => $this->inspect($service, $args),
            'feature:map' => [
                'status' => 0,
                'message' => null,
                'payload' => $service->map(),
            ],
            default => throw new FoundryError('FEATURE_COMMAND_INVALID', 'validation', ['command' => $command], 'Unsupported feature command.'),
        };
    }

    /**
     * @param array<int,string> $args
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    private function inspect(FeatureWorkspaceService $service, array $args): array
    {
        $feature = trim((string) ($args[1] ?? ''));
        if ($feature === '') {
            throw new FoundryError('FEATURE_UNKNOWN', 'validation', [], 'feature:inspect requires a feature slug.');
        }

        return [
            'status' => 0,
            'message' => null,
            'payload' => $service->inspect($feature),
        ];
    }
}
