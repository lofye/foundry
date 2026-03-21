<?php
declare(strict_types=1);

namespace Foundry\Explain\Contributors;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

interface ExplainContributorInterface
{
    public function supports(ExplainSubject $subject): bool;

    /**
     * @return array<string,mixed>
     */
    public function contribute(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array;
}
