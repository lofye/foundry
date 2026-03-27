<?php

declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class RelatedCommandsAnalyzer implements SectionAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return true;
    }

    public function sectionId(): string
    {
        return 'related_commands';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        if (!$options->includeRelatedCommands) {
            return ['items' => []];
        }

        $prefix = $context->commandPrefix;
        $commands = [$prefix . ' inspect node ' . $subject->id . ' --json'];

        switch ($subject->kind) {
            case 'feature':
                $feature = (string) ($subject->metadata['feature'] ?? $subject->label);
                $commands[] = $prefix . ' inspect feature ' . $feature . ' --json';
                $commands[] = $prefix . ' inspect graph --feature=' . $feature . ' --json';
                $commands[] = $prefix . ' inspect execution-plan ' . $feature . ' --json';
                $commands[] = $prefix . ' doctor --feature=' . $feature . ' --json';
                break;
            case 'route':
                $signature = ExplainSupport::normalizeRouteSignature((string) ($subject->metadata['signature'] ?? $subject->label));
                $commands[] = $prefix . ' inspect route ' . $signature . ' --json';
                $commands[] = $prefix . ' inspect graph --command=' . $signature . ' --json';
                $commands[] = $prefix . ' inspect execution-plan ' . $signature . ' --json';
                $commands[] = $prefix . ' inspect pipeline --json';
                break;
            case 'workflow':
                $resource = (string) ($subject->metadata['resource'] ?? $subject->label);
                $commands[] = $prefix . ' graph inspect --workflow=' . $resource . ' --json';
                $commands[] = $prefix . ' inspect graph --json';
                break;
            case 'event':
                $name = (string) ($subject->metadata['name'] ?? $subject->label);
                $commands[] = $prefix . ' inspect graph --event=' . $name . ' --json';
                $commands[] = $prefix . ' verify contracts --json';
                break;
            case 'pipeline_stage':
                $commands[] = $prefix . ' inspect pipeline --json';
                $commands[] = $prefix . ' verify pipeline --json';
                break;
            case 'command':
                $commands[] = $prefix . ' help ' . $subject->label . ' --json';
                break;
            case 'schema':
                $commands[] = $prefix . ' verify contracts --json';
                break;
            case 'extension':
                $commands[] = $prefix . ' inspect extension ' . $subject->label . ' --json';
                $commands[] = $prefix . ' inspect compatibility --json';
                break;
            default:
                $commands[] = $prefix . ' inspect graph --json';
                break;
        }

        $impact = $context->impact();
        if (is_array($impact)) {
            $commands = array_merge($commands, array_values(array_map('strval', (array) ($impact['recommended_verification'] ?? []))));
        }

        return ['items' => ExplainSupport::uniqueStrings($commands)];
    }
}
