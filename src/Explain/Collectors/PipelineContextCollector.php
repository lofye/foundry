<?php
declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class PipelineContextCollector implements ExplainContextCollectorInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return in_array($subject->kind, ['feature', 'route', 'guard'], true);
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $index = $context->artifacts->executionPlanIndex();
        $byFeature = is_array($index['by_feature'] ?? null) ? $index['by_feature'] : [];
        $byRoute = is_array($index['by_route'] ?? null) ? $index['by_route'] : [];

        $feature = trim((string) ($subject->metadata['feature'] ?? ''));
        $routeSignature = trim((string) ($subject->metadata['signature'] ?? ''));
        $executionPlan = null;

        if ($subject->kind === 'feature' && $feature !== '') {
            $executionPlan = is_array($byFeature[$feature] ?? null) ? $byFeature[$feature] : null;
        } elseif ($subject->kind === 'route') {
            $routeSignature = ExplainSupport::normalizeRouteSignature($routeSignature !== '' ? $routeSignature : $subject->label);
            $executionPlan = is_array($byRoute[$routeSignature] ?? null) ? $byRoute[$routeSignature] : null;
            if ($feature === '' && is_array($executionPlan)) {
                $feature = trim((string) ($executionPlan['feature'] ?? ''));
            }
        } elseif ($subject->kind === 'guard' && $feature !== '') {
            $executionPlan = is_array($byFeature[$feature] ?? null) ? $byFeature[$feature] : null;
        }

        $guards = [];
        $guardIndex = $context->artifacts->guardIndex();
        foreach ((array) ($executionPlan['guards'] ?? []) as $guardId) {
            $guardRow = $guardIndex[(string) $guardId] ?? null;
            if (is_array($guardRow)) {
                $guards[] = $guardRow;
            }
        }

        $interceptors = [];
        $interceptorIndex = $context->artifacts->interceptorIndex();
        foreach ((array) ($executionPlan['interceptors'] ?? []) as $stage => $ids) {
            $stageRows = [];
            foreach ((array) $ids as $id) {
                $row = $interceptorIndex[(string) $id] ?? null;
                if (is_array($row)) {
                    $stageRows[] = $row;
                }
            }

            if ($stageRows !== []) {
                $interceptors[(string) $stage] = $stageRows;
            }
        }

        $context->set('pipeline', [
            'feature' => $feature !== '' ? $feature : null,
            'route_signature' => $routeSignature !== '' ? $routeSignature : ($executionPlan['route_signature'] ?? null),
            'execution_plan' => $executionPlan,
            'guards' => $guards,
            'interceptors' => $interceptors,
            'definition' => $context->artifacts->pipelineIndex(),
        ]);
    }
}
