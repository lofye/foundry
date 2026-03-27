<?php
declare(strict_types=1);

namespace Foundry\Explain\Contributors;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final readonly class ExplainContributorRegistry
{
    /**
     * @param array<int,ExplainContributorInterface> $contributors
     */
    public function __construct(private array $contributors = [])
    {
    }

    /**
     * @return array<int,ExplainContribution>
     */
    public function contributionsFor(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $contributions = [];
        foreach ($this->contributors as $contributor) {
            if (!$contributor->supports($subject)) {
                continue;
            }

            $contribution = $contributor->contribute($subject, $context, $options);
            $contributions[] = $contribution instanceof ExplainContribution
                ? $contribution
                : ExplainContribution::fromArray((array) $contribution);
        }

        return $contributions;
    }
}
