<?php
declare(strict_types=1);

namespace Foundry\Explain;

use Foundry\Support\FoundryError;

final class AmbiguousExplainTargetException
{
    /**
     * @param array<int,array<string,mixed>> $candidates
     */
    public static function raise(string $target, array $candidates, string $message, ?string $kind = null): never
    {
        $details = [
            'target' => $target,
            'candidates' => $candidates,
        ];

        if ($kind !== null && $kind !== '') {
            $details['kind'] = $kind;
        }

        throw new FoundryError(
            'EXPLAIN_TARGET_AMBIGUOUS',
            'validation',
            $details,
            $message,
        );
    }
}
