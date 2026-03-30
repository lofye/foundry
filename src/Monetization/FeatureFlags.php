<?php

declare(strict_types=1);

namespace Foundry\Monetization;

final class FeatureFlags
{
    public const PRO_DEEP_DIAGNOSTICS = 'feature.pro.deep_diagnostics';
    public const PRO_EXPLAIN_PLUS = 'feature.pro.explain_plus';
    public const PRO_GRAPH_DIFF = 'feature.pro.graph_diff';
    public const PRO_TRACE = 'feature.pro.trace';
    public const PRO_GENERATE = 'feature.pro.generate';
    public const HOSTED_SYNC = 'feature.hosted.sync';

    /**
     * @return list<string>
     */
    public static function pro(): array
    {
        return [
            self::PRO_DEEP_DIAGNOSTICS,
            self::PRO_EXPLAIN_PLUS,
            self::PRO_GRAPH_DIFF,
            self::PRO_TRACE,
            self::PRO_GENERATE,
        ];
    }
}
