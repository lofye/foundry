<?php

declare(strict_types=1);

namespace Foundry\Monetization;

final class FeatureFlags
{
    public const TIER_FREE = 'free';
    public const TIER_PRO = 'pro';

    public const PRO_DEEP_DIAGNOSTICS = 'feature.pro.deep_diagnostics';
    public const PRO_EXPLAIN_PLUS = 'feature.pro.explain_plus';
    public const PRO_GRAPH_DIFF = 'feature.pro.graph_diff';
    public const PRO_TRACE = 'feature.pro.trace';
    public const PRO_GENERATE = 'feature.pro.generate';
    public const HOSTED_SYNC = 'feature.hosted.sync';

    /**
     * @var array<string,array{name:string,summary:string,visible:bool}>
     */
    private const PUBLIC_CATALOG = [
        self::PRO_DEEP_DIAGNOSTICS => [
            'name' => 'doctor.deep',
            'summary' => 'Deep diagnostics',
            'visible' => true,
        ],
        self::PRO_EXPLAIN_PLUS => [
            'name' => 'explain.advanced',
            'summary' => 'Advanced explain output',
            'visible' => true,
        ],
        self::PRO_GRAPH_DIFF => [
            'name' => 'diff.graph',
            'summary' => 'Graph diff analysis',
            'visible' => true,
        ],
        self::PRO_TRACE => [
            'name' => 'trace.analysis',
            'summary' => 'Trace analysis',
            'visible' => true,
        ],
        self::PRO_GENERATE => [
            'name' => 'generate.full',
            'summary' => 'Prompt-based generation',
            'visible' => true,
        ],
        self::HOSTED_SYNC => [
            'name' => 'sync.hosted',
            'summary' => 'Hosted sync',
            'visible' => false,
        ],
    ];

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

    /**
     * @return list<string>
     */
    public static function licensed(): array
    {
        return self::pro();
    }

    /**
     * @return array<string,array{name:string,summary:string,visible:bool}>
     */
    public static function catalog(bool $visibleOnly = false): array
    {
        $catalog = self::PUBLIC_CATALOG;

        if ($visibleOnly) {
            $catalog = array_filter(
                $catalog,
                static fn(array $metadata): bool => ($metadata['visible'] ?? false) === true,
            );
        }

        uasort(
            $catalog,
            static fn(array $left, array $right): int => strcmp(
                (string) ($left['name'] ?? ''),
                (string) ($right['name'] ?? ''),
            ),
        );

        return $catalog;
    }

    public static function publicName(string $feature): string
    {
        return (string) (self::PUBLIC_CATALOG[$feature]['name'] ?? $feature);
    }

    /**
     * @param array<int,string> $features
     * @return list<string>
     */
    public static function publicNames(array $features): array
    {
        $names = array_values(array_unique(array_map(
            static fn(string $feature): string => self::publicName($feature),
            $features,
        )));

        sort($names);

        return $names;
    }

    /**
     * @return array<string,list<string>>
     */
    public static function requiredTiers(): array
    {
        return [
            self::PRO_DEEP_DIAGNOSTICS => [self::TIER_PRO],
            self::PRO_EXPLAIN_PLUS => [self::TIER_PRO],
            self::PRO_GRAPH_DIFF => [self::TIER_PRO],
            self::PRO_TRACE => [self::TIER_PRO],
            self::PRO_GENERATE => [self::TIER_PRO],
            self::HOSTED_SYNC => [self::TIER_PRO],
        ];
    }

    /**
     * @return list<string>
     */
    public static function enabledForTier(string $tier): array
    {
        $enabled = [];

        foreach (self::requiredTiers() as $feature => $tiers) {
            if (in_array($tier, $tiers, true)) {
                $enabled[] = $feature;
            }
        }

        sort($enabled);

        return $enabled;
    }
}
