<?php
declare(strict_types=1);

namespace Foundry\Feature;

use Foundry\Auth\AuthContext;
use Foundry\Auth\AuthorizationEngine;
use Foundry\DB\TransactionManager;
use Foundry\Http\RequestContext;
use Foundry\Http\RouteMatcher;
use Foundry\Observability\AuditRecorder;
use Foundry\Observability\TraceRecorder;
use Foundry\Pipeline\PipelineDefinitionResolver;
use Foundry\Pipeline\PipelineExecutionState;
use Foundry\Pipeline\StageInterceptor;
use Foundry\Schema\SchemaValidator;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Str;

final class FeatureExecutor
{
    /**
     * @var array<string,int>
     */
    private static array $rateLimitCounters = [];

    /**
     * @param array<int,StageInterceptor> $registeredInterceptors
     * @param array<int,string>|null $configuredStageOrder
     */
    public function __construct(
        private readonly FeatureLoader $features,
        private readonly AuthorizationEngine $authorization,
        private readonly SchemaValidator $schemas,
        private readonly TransactionManager $transactions,
        private readonly FeatureServices $services,
        private readonly TraceRecorder $trace,
        private readonly AuditRecorder $audit,
        private readonly Paths $paths,
        private readonly RouteMatcher $matcher = new RouteMatcher(),
        private readonly array $registeredInterceptors = [],
        private readonly ?array $configuredStageOrder = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function executeHttp(RequestContext $request): array
    {
        $requestStart = microtime(true);
        $this->trace->record('unknown', 'http', 'request_start', ['method' => $request->method(), 'path' => $request->path()]);

        $match = $this->matcher->match($this->features->routes(), $request);
        if ($match === null) {
            throw new FoundryError(
                'ROUTE_NOT_FOUND',
                'not_found',
                ['method' => $request->method(), 'path' => $request->path()],
                'No route matched this request.'
            );
        }

        $route = $match['route'];
        $request = $match['request'];

        $feature = $this->features->get($route->feature);
        $state = new PipelineExecutionState($request, $route, $feature, $this->services, $this->trace, $this->audit);
        $state->metadata['request_started_at'] = $requestStart;
        $state->metadata['response_status'] = 200;

        $executionPlan = $this->executionPlanForRoute($route->key(), $feature->name);
        $pipeline = $this->features->pipelineDefinition();
        $stages = array_values(array_map('strval', (array) ($executionPlan['stages'] ?? [])));
        if ($stages === []) {
            $stages = array_values(array_map('strval', (array) ($pipeline['order'] ?? [])));
        }
        if ($stages === [] && is_array($this->configuredStageOrder)) {
            $stages = array_values(array_map('strval', $this->configuredStageOrder));
        }
        if ($stages === []) {
            $stages = PipelineDefinitionResolver::defaultStages();
        }

        $guardsByType = $this->guardsByType((array) ($executionPlan['guards'] ?? []), $feature);
        $interceptorsByStage = $this->runtimeInterceptorsByStage((array) ($executionPlan['interceptors'] ?? []));

        try {
            foreach ($stages as $stage) {
                $this->runStageInterceptors($stage, $state, $interceptorsByStage);

                switch ($stage) {
                    case 'routing':
                        $this->trace->record($feature->name, 'http', 'route_match', ['route' => $route->key()]);
                        break;

                    case 'before_auth':
                        if (isset($guardsByType['rate_limit'])) {
                            foreach ($guardsByType['rate_limit'] as $guard) {
                                $this->enforceRateLimit($guard, $state);
                            }
                        }
                        break;

                    case 'auth':
                        if (isset($guardsByType['authentication']) || isset($guardsByType['permission'])) {
                            $auth = $this->authorization->authenticate($feature, $state->request);
                            $decision = $this->authorization->authorize($feature, $auth);
                            $this->trace->record($feature->name, 'auth', 'authorization_decision', ['allowed' => $decision->allowed, 'reason' => $decision->reason]);
                            if (!$decision->allowed) {
                                throw new FoundryError(
                                    'AUTHORIZATION_DENIED',
                                    'authorization',
                                    ['feature' => $feature->name, 'reason' => $decision->reason],
                                    'Access denied.',
                                );
                            }
                            $state->auth = $auth;
                        } else {
                            $state->auth = AuthContext::guest();
                        }
                        break;

                    case 'before_validation':
                        if (isset($guardsByType['csrf'])) {
                            $this->enforceCsrf($state);
                        }
                        break;

                    case 'validation':
                        if (isset($guardsByType['request_validation']) || $feature->inputSchemaPath !== '') {
                            $inputPath = $this->resolveSchemaPath($feature->inputSchemaPath);
                            $state->input = $state->request->input();
                            $inputValidation = $this->schemas->validate($state->input, $inputPath);
                            $this->trace->record($feature->name, 'schema', 'input_validation', ['ok' => $inputValidation->isValid]);

                            if (!$inputValidation->isValid) {
                                throw new FoundryError(
                                    'FEATURE_INPUT_SCHEMA_VIOLATION',
                                    'validation',
                                    ['feature' => $feature->name, 'errors' => array_map(static fn ($e): array => $e->toArray(), $inputValidation->errors)],
                                    'Input does not match schema.',
                                );
                            }
                        }
                        break;

                    case 'before_action':
                        if ((isset($guardsByType['transaction']) || $feature->requiresTransaction()) && !$state->transactionOpened) {
                            $this->transactions->begin();
                            $state->transactionOpened = true;
                            $this->trace->record($feature->name, 'db', 'transaction_begin');
                        }
                        break;

                    case 'action':
                        $action = $this->resolveAction($feature);
                        $state->output = $action->handle(
                            $state->input,
                            $state->request,
                            $state->auth ?? AuthContext::guest(),
                            $this->services,
                        );
                        break;

                    case 'after_action':
                        $outputPath = $this->resolveSchemaPath($feature->outputSchemaPath);
                        $outputValidation = $this->schemas->validate($state->output, $outputPath);
                        $this->trace->record($feature->name, 'schema', 'output_validation', ['ok' => $outputValidation->isValid]);
                        if (!$outputValidation->isValid) {
                            throw new FoundryError(
                                'FEATURE_OUTPUT_SCHEMA_VIOLATION',
                                'validation',
                                ['feature' => $feature->name, 'errors' => array_map(static fn ($e): array => $e->toArray(), $outputValidation->errors)],
                                'Output does not match schema.',
                            );
                        }

                        if ($state->transactionOpened) {
                            $this->transactions->commit();
                            $state->transactionOpened = false;
                            $this->trace->record($feature->name, 'db', 'transaction_commit');
                        }
                        break;

                    case 'response_serialization':
                        if (!is_array($state->output)) {
                            throw new FoundryError(
                                'FEATURE_OUTPUT_INVALID',
                                'validation',
                                ['feature' => $feature->name],
                                'Feature output must be an array.',
                            );
                        }
                        break;

                    case 'response_send':
                        $this->audit->record($feature->name, 'feature_executed', [
                            'route' => $route->key(),
                            'auth_user' => ($state->auth ?? AuthContext::guest())->userId(),
                        ]);
                        $this->trace->record($feature->name, 'http', 'response_emit', [], (microtime(true) - $requestStart) * 1000);
                        break;
                }
            }
        } catch (\Throwable $e) {
            if ($state->transactionOpened && $this->transactions->inTransaction()) {
                $this->transactions->rollBack();
                $state->transactionOpened = false;
                $this->trace->record($feature->name, 'db', 'transaction_rollback', ['exception' => $e::class]);
            }
            throw $e;
        }

        return $state->output;
    }

    private function resolveSchemaPath(string $path): string
    {
        if ($path === '') {
            throw new FoundryError('SCHEMA_PATH_EMPTY', 'validation', [], 'Schema path cannot be empty.');
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->paths->join($path);
    }

    private function resolveAction(FeatureDefinition $feature): FeatureAction
    {
        $class = $feature->actionClass;
        $actionFile = $this->paths->join('app/features/' . $feature->name . '/action.php');
        if (is_file($actionFile)) {
            require_once $actionFile;
        }

        if ($class === null || $class === '') {
            $class = 'App\\Features\\' . Str::studly($feature->name) . '\\Action';
        }

        if (!class_exists($class)) {
            throw new FoundryError('FEATURE_ACTION_CLASS_NOT_FOUND', 'not_found', ['feature' => $feature->name, 'class' => $class], 'Feature action class not found.');
        }

        $action = new $class();
        if (!$action instanceof FeatureAction) {
            throw new FoundryError('FEATURE_ACTION_CONTRACT_VIOLATION', 'validation', ['class' => $class], 'Action must implement FeatureAction.');
        }

        return $action;
    }

    /**
     * @return array<string,mixed>
     */
    private function executionPlanForRoute(string $routeSignature, string $feature): array
    {
        $plans = $this->features->executionPlans();
        $byRoute = is_array($plans['by_route'] ?? null) ? $plans['by_route'] : [];
        $byFeature = is_array($plans['by_feature'] ?? null) ? $plans['by_feature'] : [];

        if (is_array($byRoute[$routeSignature] ?? null)) {
            return $byRoute[$routeSignature];
        }

        if (is_array($byFeature[$feature] ?? null)) {
            return $byFeature[$feature];
        }

        return [
            'stages' => PipelineDefinitionResolver::defaultStages(),
            'guards' => [],
            'interceptors' => [],
        ];
    }

    /**
     * @param array<int|string,mixed> $guardIds
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function guardsByType(array $guardIds, ?FeatureDefinition $feature = null): array
    {
        $index = $this->features->guards();
        $guardRows = [];
        foreach ($guardIds as $value) {
            $id = (string) $value;
            if ($id === '') {
                continue;
            }
            $row = is_array($index[$id] ?? null) ? $index[$id] : null;
            if ($row === null) {
                continue;
            }
            $type = (string) ($row['type'] ?? ($row['config']['type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $guardRows[$type] ??= [];
            $guardRows[$type][] = is_array($row['config'] ?? null) ? $row['config'] : $row;
        }

        if ($guardRows === [] && $feature instanceof FeatureDefinition) {
            $auth = $feature->auth;
            $permissions = array_values(array_filter(array_map('strval', (array) ($auth['permissions'] ?? []))));
            if ((bool) ($auth['required'] ?? false) || $permissions !== []) {
                $guardRows['authentication'] = [['feature' => $feature->name, 'type' => 'authentication']];
            }

            if ($feature->inputSchemaPath !== '') {
                $guardRows['request_validation'] = [['feature' => $feature->name, 'type' => 'request_validation']];
            }

            if ($feature->requiresTransaction()) {
                $guardRows['transaction'] = [['feature' => $feature->name, 'type' => 'transaction']];
            }

            if ($feature->rateLimit !== []) {
                $guardRows['rate_limit'] = [[
                    'feature' => $feature->name,
                    'type' => 'rate_limit',
                    'strategy' => (string) ($feature->rateLimit['strategy'] ?? 'user'),
                    'bucket' => (string) ($feature->rateLimit['bucket'] ?? $feature->name),
                    'cost' => (int) ($feature->rateLimit['cost'] ?? 1),
                ]];
            }
        }

        ksort($guardRows);

        return $guardRows;
    }

    /**
     * @param array<int|string,mixed> $compiledMap
     * @return array<string,array<int,StageInterceptor>>
     */
    private function runtimeInterceptorsByStage(array $compiledMap): array
    {
        $registered = [];
        foreach ($this->registeredInterceptors as $interceptor) {
            if (!$interceptor instanceof StageInterceptor) {
                continue;
            }
            $registered[$interceptor->id()] = $interceptor;
        }
        ksort($registered);

        $byStage = [];
        foreach ($compiledMap as $stage => $ids) {
            $stageName = (string) $stage;
            if ($stageName === '') {
                continue;
            }

            foreach ((array) $ids as $idRaw) {
                $id = (string) $idRaw;
                $interceptor = $registered[$id] ?? null;
                if (!$interceptor instanceof StageInterceptor) {
                    continue;
                }
                $byStage[$stageName] ??= [];
                $byStage[$stageName][] = $interceptor;
            }
        }

        if ($compiledMap === []) {
            foreach ($registered as $interceptor) {
                $stage = $interceptor->stage();
                $byStage[$stage] ??= [];
                $byStage[$stage][] = $interceptor;
            }
        }

        foreach ($byStage as &$interceptors) {
            usort(
                $interceptors,
                static fn (StageInterceptor $a, StageInterceptor $b): int => ($a->priority() <=> $b->priority())
                    ?: strcmp($a->id(), $b->id()),
            );
        }
        unset($interceptors);
        ksort($byStage);

        return $byStage;
    }

    /**
     * @param array<string,array<int,StageInterceptor>> $interceptorsByStage
     */
    private function runStageInterceptors(string $stage, PipelineExecutionState $state, array $interceptorsByStage): void
    {
        foreach ($interceptorsByStage[$stage] ?? [] as $interceptor) {
            $interceptor->handle($state);
        }
    }

    private function enforceCsrf(PipelineExecutionState $state): void
    {
        if (!in_array($state->request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $token = $state->request->header('x-csrf-token');
        if ($token !== null && trim($token) !== '') {
            return;
        }

        throw new FoundryError(
            'CSRF_TOKEN_MISSING',
            'authorization',
            ['feature' => $state->feature->name],
            'Missing CSRF token.',
        );
    }

    /**
     * @param array<string,mixed> $guard
     */
    private function enforceRateLimit(array $guard, PipelineExecutionState $state): void
    {
        $bucket = (string) ($guard['bucket'] ?? $state->feature->name);
        $strategy = (string) ($guard['strategy'] ?? 'user');
        $cost = max(1, (int) ($guard['cost'] ?? 1));
        $limit = max(1, (int) ($guard['limit'] ?? 60));

        $user = (string) ($state->request->header('x-user-id', 'guest') ?? 'guest');
        $subject = match ($strategy) {
            'global' => 'global',
            'route' => $state->route->key(),
            default => $user,
        };

        $key = $bucket . '|' . $subject;
        $current = self::$rateLimitCounters[$key] ?? 0;
        if ($current + $cost > $limit) {
            throw new FoundryError(
                'RATE_LIMIT_EXCEEDED',
                'authorization',
                ['feature' => $state->feature->name, 'bucket' => $bucket, 'strategy' => $strategy],
                'Rate limit exceeded.',
            );
        }

        self::$rateLimitCounters[$key] = $current + $cost;
    }
}
