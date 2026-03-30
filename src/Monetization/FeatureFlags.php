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
     * @var array<string,array{name:string,summary:string,type:string,monetization:string,visible:bool}>
     */
    private const DEFINITIONS = [
        self::PRO_DEEP_DIAGNOSTICS => [
            'name' => 'doctor.deep',
            'summary' => 'Deep diagnostics',
            'type' => 'capability',
            'monetization' => 'none',
            'visible' => true,
        ],
        self::PRO_EXPLAIN_PLUS => [
            'name' => 'explain.advanced',
            'summary' => 'Advanced explain output',
            'type' => 'capability',
            'monetization' => 'none',
            'visible' => true,
        ],
        self::PRO_GRAPH_DIFF => [
            'name' => 'diff.graph',
            'summary' => 'Graph diff analysis',
            'type' => 'capability',
            'monetization' => 'none',
            'visible' => true,
        ],
        self::PRO_TRACE => [
            'name' => 'trace.analysis',
            'summary' => 'Trace analysis',
            'type' => 'capability',
            'monetization' => 'none',
            'visible' => true,
        ],
        self::PRO_GENERATE => [
            'name' => 'generate.full',
            'summary' => 'Prompt-based generation',
            'type' => 'capability',
            'monetization' => 'none',
            'visible' => true,
        ],
        self::HOSTED_SYNC => [
            'name' => 'marketplace.access',
            'summary' => 'Marketplace participation',
            'type' => 'service',
            'monetization' => 'licensed',
            'visible' => true,
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
        return self::serviceManaged();
    }

    /**
     * @return array<string,array{name:string,summary:string,type:string,monetization:string,visible:bool}>
     */
    public static function catalog(bool $visibleOnly = false): array
    {
        $catalog = self::DEFINITIONS;

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
        return (string) (self::DEFINITIONS[$feature]['name'] ?? $feature);
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
     * @return list<string>
     */
    public static function capabilities(): array
    {
        return array_keys(array_filter(
            self::DEFINITIONS,
            static fn(array $definition): bool => (string) ($definition['type'] ?? 'capability') === 'capability',
        ));
    }

    /**
     * @return list<string>
     */
    public static function serviceManaged(): array
    {
        return array_keys(array_filter(
            self::DEFINITIONS,
            static fn(array $definition): bool => (string) ($definition['monetization'] ?? 'none') === 'licensed',
        ));
    }

    /**
     * @return array{name:string,summary:string,type:string,monetization:string,visible:bool}
     */
    public static function definition(string $feature): array
    {
        return self::DEFINITIONS[$feature] ?? [
            'name' => $feature,
            'summary' => '',
            'type' => 'capability',
            'monetization' => 'none',
            'visible' => false,
        ];
    }

    public static function type(string $feature): string
    {
        return (string) (self::definition($feature)['type'] ?? 'capability');
    }

    public static function monetization(string $feature): string
    {
        return (string) (self::definition($feature)['monetization'] ?? 'none');
    }

    /**
     * @return list<string>
     */
    public static function enabledForTier(string $tier): array
    {
        $enabled = [];

        foreach (self::DEFINITIONS as $feature => $definition) {
            $monetization = (string) ($definition['monetization'] ?? 'none');

            if ($monetization === 'licensed' && $tier !== self::TIER_FREE) {
                $enabled[] = $feature;
            }
        }

        sort($enabled);

        return $enabled;
    }
}
