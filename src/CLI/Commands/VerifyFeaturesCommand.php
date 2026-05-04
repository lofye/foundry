<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\FeatureSystem\FeatureWorkspaceService;

final class VerifyFeaturesCommand extends Command
{
    public function __construct(private readonly ?FeatureWorkspaceService $service = null) {}

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['verify features'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'verify' && ($args[1] ?? null) === 'features';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $service = $this->service ?? new FeatureWorkspaceService($context->paths());
        $payload = $service->verify();

        return [
            'status' => ((string) ($payload['status'] ?? 'failed')) === 'ok' ? 0 : 1,
            'message' => null,
            'payload' => $payload,
        ];
    }
}
