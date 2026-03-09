<?php
declare(strict_types=1);

namespace Foundry\Extensions\Demo;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;

final class DemoExtensionPass implements CompilerPass
{
    public function name(): string
    {
        return 'demo_extension_enrich';
    }

    public function run(CompilationState $state): void
    {
        $state->diagnostics->info(
            code: 'FDY8001_DEMO_EXTENSION_ACTIVE',
            category: 'extensions',
            message: 'Demo extension pass executed.',
            pass: $this->name(),
        );

        $metadata = $state->graph->metadata();
        $metadata['demo_extension'] = [
            'active' => true,
            'note' => 'Added by Foundry demo extension.',
        ];
        $state->graph->setMetadata($metadata);
    }
}
