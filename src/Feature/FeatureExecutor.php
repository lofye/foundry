<?php
declare(strict_types=1);

namespace Forge\Feature;

use Forge\Auth\AuthContext;
use Forge\Auth\AuthorizationEngine;
use Forge\DB\TransactionManager;
use Forge\Http\RequestContext;
use Forge\Http\RouteMatcher;
use Forge\Observability\AuditRecorder;
use Forge\Observability\TraceRecorder;
use Forge\Schema\SchemaValidator;
use Forge\Support\ForgeError;
use Forge\Support\Paths;
use Forge\Support\Str;

final class FeatureExecutor
{
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
            throw new ForgeError(
                'ROUTE_NOT_FOUND',
                'not_found',
                ['method' => $request->method(), 'path' => $request->path()],
                'No route matched this request.'
            );
        }

        $route = $match['route'];
        $request = $match['request'];

        $feature = $this->features->get($route->feature);
        $this->trace->record($feature->name, 'http', 'route_match', ['route' => $route->key()]);

        $auth = $this->authorization->authenticate($feature, $request);
        $decision = $this->authorization->authorize($feature, $auth);
        $this->trace->record($feature->name, 'auth', 'authorization_decision', ['allowed' => $decision->allowed, 'reason' => $decision->reason]);

        if (!$decision->allowed) {
            throw new ForgeError(
                'AUTHORIZATION_DENIED',
                'authorization',
                ['feature' => $feature->name, 'reason' => $decision->reason],
                'Access denied.'
            );
        }

        $inputPath = $this->resolveSchemaPath($feature->inputSchemaPath);
        $input = $request->input();
        $inputValidation = $this->schemas->validate($input, $inputPath);
        $this->trace->record($feature->name, 'schema', 'input_validation', ['ok' => $inputValidation->isValid]);

        if (!$inputValidation->isValid) {
            throw new ForgeError(
                'FEATURE_INPUT_SCHEMA_VIOLATION',
                'validation',
                ['feature' => $feature->name, 'errors' => array_map(static fn ($e): array => $e->toArray(), $inputValidation->errors)],
                'Input does not match schema.'
            );
        }

        $openedTransaction = false;
        if ($feature->requiresTransaction()) {
            $this->transactions->begin();
            $openedTransaction = true;
            $this->trace->record($feature->name, 'db', 'transaction_begin');
        }

        try {
            $action = $this->resolveAction($feature);
            $output = $action->handle($input, $request, $auth, $this->services);

            $outputPath = $this->resolveSchemaPath($feature->outputSchemaPath);
            $outputValidation = $this->schemas->validate($output, $outputPath);
            $this->trace->record($feature->name, 'schema', 'output_validation', ['ok' => $outputValidation->isValid]);

            if (!$outputValidation->isValid) {
                throw new ForgeError(
                    'FEATURE_OUTPUT_SCHEMA_VIOLATION',
                    'validation',
                    ['feature' => $feature->name, 'errors' => array_map(static fn ($e): array => $e->toArray(), $outputValidation->errors)],
                    'Output does not match schema.'
                );
            }

            if ($openedTransaction) {
                $this->transactions->commit();
                $this->trace->record($feature->name, 'db', 'transaction_commit');
            }

            $this->audit->record($feature->name, 'feature_executed', [
                'route' => $route->key(),
                'auth_user' => $auth->userId(),
            ]);
            $this->trace->record($feature->name, 'http', 'response_emit', [], (microtime(true) - $requestStart) * 1000);

            return $output;
        } catch (\Throwable $e) {
            if ($openedTransaction && $this->transactions->inTransaction()) {
                $this->transactions->rollBack();
                $this->trace->record($feature->name, 'db', 'transaction_rollback', ['exception' => $e::class]);
            }

            throw $e;
        }
    }

    private function resolveSchemaPath(string $path): string
    {
        if ($path === '') {
            throw new ForgeError('SCHEMA_PATH_EMPTY', 'validation', [], 'Schema path cannot be empty.');
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
            throw new ForgeError('FEATURE_ACTION_CLASS_NOT_FOUND', 'not_found', ['feature' => $feature->name, 'class' => $class], 'Feature action class not found.');
        }

        $action = new $class();
        if (!$action instanceof FeatureAction) {
            throw new ForgeError('FEATURE_ACTION_CONTRACT_VIOLATION', 'validation', ['class' => $class], 'Action must implement FeatureAction.');
        }

        return $action;
    }
}
