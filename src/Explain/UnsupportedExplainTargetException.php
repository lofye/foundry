<?php
declare(strict_types=1);

namespace Foundry\Explain;

use Foundry\Support\FoundryError;

final class UnsupportedExplainTargetException
{
    /**
     * @param array<int,string> $supportedKinds
     */
    public static function raise(string $kind, array $supportedKinds = ExplainTarget::SUPPORTED_KINDS): never
    {
        throw new FoundryError(
            'EXPLAIN_TARGET_KIND_UNSUPPORTED',
            'validation',
            [
                'kind' => $kind,
                'supported_kinds' => array_values($supportedKinds),
            ],
            'Unsupported explain target kind: ' . $kind . '.',
        );
    }
}
