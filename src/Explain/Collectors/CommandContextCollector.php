<?php

declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Explain\ExplainArtifactCatalog;
use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final readonly class CommandContextCollector implements ExplainContextCollectorInterface
{
    public function __construct(private ExplainArtifactCatalog $artifacts) {}

    public function supports(ExplainSubject $subject): bool
    {
        return true;
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $context->setCommands([
            'subject' => $subject->kind === 'command' ? $subject->metadata : null,
            'candidates' => $this->artifacts->cliCommands(),
        ]);
    }
}
