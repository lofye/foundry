<?php
declare(strict_types=1);

namespace Foundry\Explain;

final class SuggestedFixesBuilder
{
    /**
     * @param array<string,mixed> $sections
     * @return array<int,string>
     */
    public function build(ExplainSubject $subject, array $sections): array
    {
        $fixes = [];

        foreach ((array) ($sections['diagnostics']['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $suggestedFix = trim((string) ($row['suggested_fix'] ?? ''));
            if ($suggestedFix !== '') {
                $fixes[] = $suggestedFix;
            }

            $message = trim((string) ($row['message'] ?? ''));
            if ($message !== '' && str_contains(strtolower($message), 'no subscribers:')) {
                $event = trim((string) substr($message, strrpos($message, ':') + 1));
                if ($event !== '') {
                    $fixes[] = 'Add a subscriber or workflow for event: ' . $event;
                }
            }
        }

        foreach ((array) ($sections['permissions']['missing'] ?? []) as $permission) {
            $permission = trim((string) $permission);
            if ($permission !== '') {
                $fixes[] = 'Add permission mapping for: ' . $permission;
            }
        }

        return ExplainSupport::orderedUniqueStrings($fixes);
    }
}
