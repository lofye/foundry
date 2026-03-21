<?php
declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final class ExtensionContextCollector implements ExplainContextCollectorInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'extension';
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $context->set('extension', $subject->metadata);
    }
}
