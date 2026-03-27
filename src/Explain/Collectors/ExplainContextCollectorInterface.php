<?php

declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

interface ExplainContextCollectorInterface
{
    public function supports(ExplainSubject $subject): bool;

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void;
}
