<?php
declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Explain\ExplainArtifactCatalog;
use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final readonly class ExtensionContextCollector implements ExplainContextCollectorInterface
{
    public function __construct(private ExplainArtifactCatalog $artifacts)
    {
    }

    public function supports(ExplainSubject $subject): bool
    {
        return true;
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $context->setExtensions([
            'subject' => $subject->kind === 'extension' ? $subject->metadata : null,
            'items' => $this->artifacts->extensions(),
        ]);
    }
}
