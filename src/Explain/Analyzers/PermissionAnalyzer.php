<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final class PermissionAnalyzer implements SectionAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return in_array($subject->kind, ['feature', 'route'], true);
    }

    public function sectionId(): string
    {
        return 'permissions';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $pipeline = $context->pipeline();
        $required = [];
        foreach (array_values(array_filter((array) ($pipeline['permissions'] ?? []), 'is_array')) as $permission) {
            $required[] = (string) ($permission['name'] ?? '');
        }

        $required = array_values(array_filter($required, static fn (string $value): bool => $value !== ''));
        sort($required);

        $enforcedBy = [];
        foreach (array_values(array_filter((array) ($pipeline['guards'] ?? []), 'is_array')) as $guard) {
            if ((string) ($guard['type'] ?? '') !== 'permission') {
                continue;
            }

            $permission = trim((string) ($guard['config']['permission'] ?? ''));
            $enforcedBy[] = [
                'guard' => (string) ($guard['id'] ?? 'permission guard'),
                'permission' => $permission,
                'stage' => (string) ($guard['stage'] ?? ''),
            ];
        }

        $definedIn = [];
        $missing = [];
        $feature = trim((string) ($pipeline['feature'] ?? $subject->metadata['feature'] ?? ''));
        foreach (array_values(array_filter((array) ($pipeline['permissions'] ?? []), 'is_array')) as $permission) {
            $name = trim((string) ($permission['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $definedIn[] = [
                'permission' => $name,
                'source' => $feature !== '' ? 'feature:' . $feature : 'feature',
            ];

            if (!is_array($permission['definition'] ?? null)) {
                $missing[] = $name;
            }
        }

        return [
            'required' => $required,
            'enforced_by' => $enforcedBy,
            'defined_in' => $definedIn,
            'missing' => array_values(array_unique($missing)),
        ];
    }
}
