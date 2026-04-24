<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class GeneratePolicyEngine
{
    private const string POLICY_PATH = '.foundry/policies/generate.json';

    /**
     * @param null|\Closure(string,string,array<string,mixed>):void $logger
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly ?GeneratePlanRiskAnalyzer $riskAnalyzer = null,
        private readonly ?\Closure $logger = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function evaluate(
        GenerationPlan $plan,
        Intent $intent,
        ?GenerationContextPacket $context = null,
        bool $overrideRequested = false,
        ?string $overrideSource = null,
    ): array {
        $policy = $this->loadPolicy();
        $facts = $this->facts($plan, $intent, $context);
        $allFindings = [];
        $warnings = [];
        $violations = [];

        foreach ($policy['rules'] as $rule) {
            $finding = $this->evaluateRule($rule, $facts);
            if ($finding === null) {
                continue;
            }

            $allFindings[] = $finding;
            if ($rule['type'] === 'warn') {
                $warnings[] = $finding;
                continue;
            }

            $violations[] = $finding;
        }

        $matchedRuleIds = [];
        $affectedActions = [];
        $affectedFiles = [];

        foreach ($allFindings as $finding) {
            $matchedRuleIds[] = (string) $finding['rule_id'];

            foreach ((array) ($finding['matched_actions'] ?? []) as $action) {
                if (!is_array($action)) {
                    continue;
                }

                $key = (string) ($action['index'] ?? '') . ':' . (string) ($action['type'] ?? '') . ':' . (string) ($action['path'] ?? '');
                $affectedActions[$key] = $action;
            }

            foreach ((array) ($finding['affected_files'] ?? []) as $path) {
                $path = trim((string) $path);
                if ($path === '') {
                    continue;
                }

                $affectedFiles[$path] = $path;
            }
        }

        $matchedRuleIds = array_values(array_unique(array_map('strval', $matchedRuleIds)));
        $affectedActions = array_values($affectedActions);
        usort(
            $affectedActions,
            static fn(array $left, array $right): int => [
                (int) ($left['index'] ?? -1),
                (string) ($left['type'] ?? ''),
                (string) ($left['path'] ?? ''),
            ] <=> [
                (int) ($right['index'] ?? -1),
                (string) ($right['type'] ?? ''),
                (string) ($right['path'] ?? ''),
            ],
        );
        $affectedFiles = array_values($affectedFiles);
        sort($affectedFiles);

        $status = $violations !== []
            ? 'deny'
            : ($warnings !== [] ? 'warn' : 'pass');
        $overrideAvailable = $violations !== [];
        $overrideUsed = $overrideRequested && $overrideAvailable;

        return [
            'loaded' => $policy['loaded'],
            'path' => self::POLICY_PATH,
            'version' => $policy['version'],
            'status' => $status,
            'warnings' => $warnings,
            'violations' => $violations,
            'matched_rule_ids' => $matchedRuleIds,
            'affected_actions' => $affectedActions,
            'affected_files' => $affectedFiles,
            'blocking' => $violations !== [] && !$overrideUsed,
            'override_available' => $overrideAvailable,
            'override_requested' => $overrideRequested,
            'override_used' => $overrideUsed,
            'override_source' => $overrideUsed ? $overrideSource : null,
        ];
    }

    /**
     * @return array{loaded:bool,version:int|null,rules:list<array<string,mixed>>}
     */
    private function loadPolicy(): array
    {
        $path = $this->paths->join(self::POLICY_PATH);
        if (!is_file($path)) {
            return [
                'loaded' => false,
                'version' => null,
                'rules' => [],
            ];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            $this->invalidPolicy('Policy file is empty or unreadable.', ['path' => self::POLICY_PATH]);
        }

        try {
            $decoded = Json::decodeAssoc($raw);
        } catch (FoundryError $error) {
            $this->invalidPolicy(
                'Policy file must contain valid JSON.',
                ['path' => self::POLICY_PATH, 'cause' => $error->errorCode],
                $error,
            );
        }

        $version = $decoded['version'] ?? null;
        if ($version !== 1) {
            $this->invalidPolicy(
                'Generate policy version must be `1`.',
                ['path' => self::POLICY_PATH, 'version' => $version],
            );
        }

        $rules = $decoded['rules'] ?? null;
        if (!is_array($rules) || !array_is_list($rules)) {
            $this->invalidPolicy(
                'Generate policy `rules` must be a JSON array.',
                ['path' => self::POLICY_PATH],
            );
        }

        $normalized = [];
        $ids = [];

        foreach ($rules as $index => $rule) {
            if (!is_array($rule) || array_is_list($rule)) {
                $this->invalidPolicy(
                    'Each policy rule must be a JSON object.',
                    ['path' => self::POLICY_PATH, 'rule_index' => $index],
                );
            }

            $id = trim((string) ($rule['id'] ?? ''));
            if ($id === '') {
                $this->invalidPolicy(
                    'Policy rules require a non-empty `id`.',
                    ['path' => self::POLICY_PATH, 'rule_index' => $index],
                );
            }

            if (isset($ids[$id])) {
                $this->invalidPolicy(
                    'Policy rule ids must be unique.',
                    ['path' => self::POLICY_PATH, 'rule_index' => $index, 'rule_id' => $id],
                );
            }

            $type = strtolower(trim((string) ($rule['type'] ?? '')));
            if (!in_array($type, ['deny', 'warn', 'require', 'limit'], true)) {
                $this->invalidPolicy(
                    'Policy rule type must be one of `deny`, `warn`, `require`, or `limit`.',
                    ['path' => self::POLICY_PATH, 'rule_index' => $index, 'rule_id' => $id, 'type' => $type],
                );
            }

            $description = trim((string) ($rule['description'] ?? ''));
            if ($description === '') {
                $this->invalidPolicy(
                    'Policy rules require a non-empty `description`.',
                    ['path' => self::POLICY_PATH, 'rule_index' => $index, 'rule_id' => $id],
                );
            }

            $match = $this->normalizeCriteria(
                $rule['match'] ?? [],
                ruleIndex: $index,
                ruleId: $id,
                field: 'match',
                allowEmpty: true,
            );
            $require = null;
            $limit = null;

            if ($type === 'require') {
                $require = $this->normalizeCriteria(
                    $rule['require'] ?? null,
                    ruleIndex: $index,
                    ruleId: $id,
                    field: 'require',
                    allowEmpty: false,
                );
            }

            if (array_key_exists('limit', $rule)) {
                $limit = $this->normalizeLimit($rule['limit'], $index, $id);
            }

            if ($type === 'limit' && $limit === null) {
                $this->invalidPolicy(
                    'Limit rules require a `limit` object.',
                    ['path' => self::POLICY_PATH, 'rule_index' => $index, 'rule_id' => $id],
                );
            }

            if (in_array($type, ['deny', 'warn'], true) && $match === [] && $limit === null) {
                $this->invalidPolicy(
                    'Deny and warn rules require a non-empty `match` object or a `limit` object.',
                    ['path' => self::POLICY_PATH, 'rule_index' => $index, 'rule_id' => $id],
                );
            }

            $ids[$id] = true;
            $normalized[] = [
                'id' => $id,
                'type' => $type,
                'description' => $description,
                'match' => $match,
                'require' => $require,
                'limit' => $limit,
            ];
        }

        return [
            'loaded' => true,
            'version' => 1,
            'rules' => $normalized,
        ];
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $facts
     * @return array<string,mixed>|null
     */
    private function evaluateRule(array $rule, array $facts): ?array
    {
        $matchedActions = $this->matchedActions($facts, $rule['match'] ?? []);

        if (($rule['type'] ?? null) === 'require') {
            if ($matchedActions === []) {
                return null;
            }

            $requiredActions = $this->matchedActions($facts, $rule['require'] ?? []);
            if ($requiredActions !== []) {
                return null;
            }

            $message = $rule['description'] . ' Required matching actions were not present in the plan.';

            return $this->finding($rule, $message, $matchedActions, [
                'require' => $rule['require'],
            ]);
        }

        $limit = $rule['limit'] ?? null;
        if (is_array($limit)) {
            $count = $this->limitCount($limit['kind'], $matchedActions);
            if ($count <= (int) $limit['max']) {
                return null;
            }

            $message = sprintf(
                '%s Limit exceeded for %s: %d > %d.',
                $rule['description'],
                $limit['kind'],
                $count,
                $limit['max'],
            );

            return $this->finding($rule, $message, $matchedActions, [
                'limit' => $limit,
                'observed' => $count,
            ]);
        }

        if ($matchedActions === []) {
            return null;
        }

        return $this->finding($rule, $rule['description'], $matchedActions);
    }

    /**
     * @param array<string,mixed> $rule
     * @param list<array<string,mixed>> $matchedActions
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private function finding(array $rule, string $message, array $matchedActions, array $details = []): array
    {
        $publicActions = array_map(
            static fn(array $action): array => [
                'index' => (int) $action['index'],
                'type' => (string) $action['type'],
                'path' => (string) $action['path'],
            ],
            $matchedActions,
        );
        $affectedFiles = array_values(array_unique(array_filter(array_map(
            static fn(array $action): string => trim((string) ($action['path'] ?? '')),
            $publicActions,
        ))));
        sort($affectedFiles);

        return [
            'rule_id' => (string) $rule['id'],
            'rule_type' => (string) $rule['type'],
            'description' => (string) $rule['description'],
            'message' => $message,
            'matched_actions' => $publicActions,
            'affected_files' => $affectedFiles,
            'details' => $details,
        ];
    }

    /**
     * @param array<string,mixed> $facts
     * @param array<string,mixed> $criteria
     * @return list<array<string,mixed>>
     */
    private function matchedActions(array $facts, array $criteria): array
    {
        if ($criteria === []) {
            return $facts['actions'];
        }

        $matched = [];

        foreach ($facts['actions'] as $action) {
            if (!$this->matchesCriteria($action, $criteria)) {
                continue;
            }

            $matched[] = $action;
        }

        return $matched;
    }

    /**
     * @param array<string,mixed> $action
     * @param array<string,mixed> $criteria
     */
    private function matchesCriteria(array $action, array $criteria): bool
    {
        foreach ([
            'actions' => 'type',
            'modes' => 'mode',
            'risk_levels' => 'risk_level',
            'features' => 'feature',
            'modules' => 'module',
            'graph_node_types' => 'graph_node_type',
        ] as $criteriaKey => $field) {
            $values = $criteria[$criteriaKey] ?? [];
            if ($values === []) {
                continue;
            }

            if (!in_array((string) ($action[$field] ?? ''), $values, true)) {
                return false;
            }
        }

        $paths = $criteria['paths'] ?? [];
        if ($paths !== []) {
            $path = (string) ($action['path'] ?? '');
            $matched = false;

            foreach ($paths as $pattern) {
                if ($this->pathMatches((string) $pattern, $path)) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    private function pathMatches(string $pattern, string $path): bool
    {
        $normalizedPattern = str_replace('\\', '/', trim($pattern));
        $normalizedPath = str_replace('\\', '/', trim($path));

        if ($normalizedPattern === $normalizedPath) {
            return true;
        }

        $quoted = preg_quote($normalizedPattern, '/');
        $quoted = str_replace('\*\*', '.*', $quoted);
        $quoted = str_replace('\*', '[^/]*', $quoted);

        return preg_match('/^' . $quoted . '$/', $normalizedPath) === 1;
    }

    /**
     * @return array<string,mixed>
     */
    private function facts(GenerationPlan $plan, Intent $intent, ?GenerationContextPacket $context): array
    {
        $risk = ($this->riskAnalyzer ?? new GeneratePlanRiskAnalyzer())->analyze($plan);
        $featureFromPlan = $this->normalizeName($plan->metadata['feature'] ?? null);
        $moduleFromPlan = $this->normalizeName($plan->metadata['module'] ?? null);
        $actions = [];

        foreach ($plan->actions as $index => $action) {
            if (!is_array($action)) {
                continue;
            }

            $path = trim((string) ($action['path'] ?? ''));
            $feature = $this->normalizeName($this->featureFromPath($path) ?? $featureFromPlan);
            $actions[] = [
                'index' => (int) $index,
                'type' => strtolower(trim((string) ($action['type'] ?? ''))),
                'path' => $path,
                'mode' => strtolower($intent->mode),
                'risk_level' => $this->actionRiskLevel($action),
                'feature' => $feature,
                'module' => $this->normalizeName($moduleFromPlan ?? $feature),
                'graph_node_type' => $this->graphNodeType($action['explain_node_id'] ?? null),
            ];
        }

        return [
            'actions' => $actions,
            'plan_risk' => $risk['level'] ?? 'LOW',
            'context' => $context?->toArray(),
        ];
    }

    private function actionRiskLevel(array $action): string
    {
        $type = strtolower(trim((string) ($action['type'] ?? '')));
        $summary = strtolower(trim((string) ($action['summary'] ?? '')));
        $path = strtolower(trim((string) ($action['path'] ?? '')));
        $keywords = $path . ' ' . $summary;

        if ($type === 'delete_file' || $type === 'update_schema' || str_contains($keywords, 'schema') || str_contains($keywords, 'contract')) {
            return 'HIGH';
        }

        if (in_array($type, ['update_file', 'update_docs', 'update_graph'], true)) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    /**
     * @param array<string,mixed>|null $value
     * @return array<string,mixed>|null
     */
    private function normalizeLimit(mixed $value, int $ruleIndex, string $ruleId): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value) || array_is_list($value)) {
            $this->invalidPolicy(
                'Policy `limit` must be a JSON object.',
                ['path' => self::POLICY_PATH, 'rule_index' => $ruleIndex, 'rule_id' => $ruleId],
            );
        }

        $kind = strtolower(trim((string) ($value['kind'] ?? '')));
        if (!in_array($kind, ['action_count', 'file_count', 'feature_count'], true)) {
            $this->invalidPolicy(
                'Policy limit kind must be `action_count`, `file_count`, or `feature_count`.',
                ['path' => self::POLICY_PATH, 'rule_index' => $ruleIndex, 'rule_id' => $ruleId, 'kind' => $kind],
            );
        }

        $max = $value['max'] ?? null;
        if (!is_int($max) || $max < 0) {
            $this->invalidPolicy(
                'Policy limit `max` must be a non-negative integer.',
                ['path' => self::POLICY_PATH, 'rule_index' => $ruleIndex, 'rule_id' => $ruleId, 'max' => $max],
            );
        }

        return [
            'kind' => $kind,
            'max' => $max,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeCriteria(
        mixed $value,
        int $ruleIndex,
        string $ruleId,
        string $field,
        bool $allowEmpty,
    ): array {
        if ($value === null) {
            if ($allowEmpty) {
                return [];
            }

            $this->invalidPolicy(
                sprintf('Policy `%s` must be a JSON object.', $field),
                ['path' => self::POLICY_PATH, 'rule_index' => $ruleIndex, 'rule_id' => $ruleId],
            );
        }

        if (!is_array($value)) {
            $this->invalidPolicy(
                sprintf('Policy `%s` must be a JSON object.', $field),
                ['path' => self::POLICY_PATH, 'rule_index' => $ruleIndex, 'rule_id' => $ruleId],
            );
        }

        if ($value === []) {
            if ($allowEmpty) {
                return [];
            }

            $this->invalidPolicy(
                sprintf('Policy `%s` must not be empty.', $field),
                ['path' => self::POLICY_PATH, 'rule_index' => $ruleIndex, 'rule_id' => $ruleId],
            );
        }

        if (array_is_list($value)) {
            $this->invalidPolicy(
                sprintf('Policy `%s` must be a JSON object.', $field),
                ['path' => self::POLICY_PATH, 'rule_index' => $ruleIndex, 'rule_id' => $ruleId],
            );
        }

        $allowed = ['actions', 'paths', 'modes', 'risk_levels', 'features', 'modules', 'graph_node_types'];
        $unknown = array_values(array_diff(array_keys($value), $allowed));
        if ($unknown !== []) {
            $this->invalidPolicy(
                sprintf('Policy `%s` contains unsupported keys.', $field),
                [
                    'path' => self::POLICY_PATH,
                    'rule_index' => $ruleIndex,
                    'rule_id' => $ruleId,
                    'unsupported_keys' => $unknown,
                ],
            );
        }

        $normalized = [
            'actions' => $this->normalizeStringList($value['actions'] ?? [], 'lower', $ruleIndex, $ruleId, $field, 'actions'),
            'paths' => $this->normalizeStringList($value['paths'] ?? [], null, $ruleIndex, $ruleId, $field, 'paths'),
            'modes' => $this->normalizeStringList($value['modes'] ?? [], 'lower', $ruleIndex, $ruleId, $field, 'modes'),
            'risk_levels' => $this->normalizeStringList($value['risk_levels'] ?? [], 'upper', $ruleIndex, $ruleId, $field, 'risk_levels'),
            'features' => $this->normalizeStringList($value['features'] ?? [], 'lower', $ruleIndex, $ruleId, $field, 'features'),
            'modules' => $this->normalizeStringList($value['modules'] ?? [], 'lower', $ruleIndex, $ruleId, $field, 'modules'),
            'graph_node_types' => $this->normalizeStringList($value['graph_node_types'] ?? [], 'lower', $ruleIndex, $ruleId, $field, 'graph_node_types'),
        ];
        $normalized = array_filter($normalized, static fn(array $items): bool => $items !== []);

        if (!$allowEmpty && $normalized === []) {
            $this->invalidPolicy(
                sprintf('Policy `%s` must not be empty.', $field),
                ['path' => self::POLICY_PATH, 'rule_index' => $ruleIndex, 'rule_id' => $ruleId],
            );
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(
        mixed $value,
        ?string $case,
        int $ruleIndex,
        string $ruleId,
        string $field,
        string $criteriaField,
    ): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value) || !array_is_list($value)) {
            $this->invalidPolicy(
                sprintf('Policy `%s.%s` must be a string or a JSON array of strings.', $field, $criteriaField),
                [
                    'path' => self::POLICY_PATH,
                    'rule_index' => $ruleIndex,
                    'rule_id' => $ruleId,
                    'field' => $field,
                    'criteria_field' => $criteriaField,
                ],
            );
        }

        $normalized = [];

        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }

            $normalized[] = match ($case) {
                'lower' => strtolower($item),
                'upper' => strtoupper($item),
                default => $item,
            };
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param list<array<string,mixed>> $matchedActions
     */
    private function limitCount(string $kind, array $matchedActions): int
    {
        return match ($kind) {
            'action_count' => count($matchedActions),
            'file_count' => count(array_values(array_unique(array_filter(array_map(
                static fn(array $action): string => trim((string) ($action['path'] ?? '')),
                $matchedActions,
            ))))),
            'feature_count' => count(array_values(array_unique(array_filter(array_map(
                static fn(array $action): string => trim((string) ($action['feature'] ?? '')),
                $matchedActions,
            ))))),
            default => 0,
        };
    }

    private function featureFromPath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if (!str_starts_with($normalized, 'app/features/')) {
            return null;
        }

        $parts = explode('/', $normalized);

        return $parts[2] ?? null;
    }

    private function graphNodeType(mixed $value): string
    {
        $trace = trim((string) $value);
        if ($trace === '' || !str_contains($trace, ':')) {
            return '';
        }

        return strtolower((string) strstr($trace, ':', true));
    }

    private function normalizeName(mixed $value): ?string
    {
        $name = trim((string) $value);

        return $name === '' ? null : strtolower($name);
    }

    /**
     * @param array<string,mixed> $details
     */
    private function invalidPolicy(string $message, array $details, ?\Throwable $previous = null): never
    {
        if ($this->logger instanceof \Closure) {
            ($this->logger)('policy_invalid', $message, $details);
        }

        throw new FoundryError(
            'GENERATE_POLICY_INVALID',
            'validation',
            $details,
            $message,
            0,
            $previous,
        );
    }
}
