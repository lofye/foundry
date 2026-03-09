<?php
declare(strict_types=1);

namespace Foundry\Core;

use Foundry\AI\AIManager;
use Foundry\AI\StaticAIProvider;
use Foundry\Auth\AuthorizationEngine;
use Foundry\Auth\HeaderTokenAuthenticator;
use Foundry\Auth\PermissionRegistry;
use Foundry\Cache\ArrayCacheStore;
use Foundry\Cache\CacheDefinition;
use Foundry\Cache\CacheManager;
use Foundry\Cache\CacheRegistry;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\DB\Connection;
use Foundry\DB\PdoQueryExecutor;
use Foundry\DB\QueryDefinition;
use Foundry\DB\QueryRegistry;
use Foundry\DB\SqlFileLoader;
use Foundry\DB\TransactionManager;
use Foundry\Events\DefaultEventDispatcher;
use Foundry\Events\EventDefinition;
use Foundry\Events\EventRegistry;
use Foundry\Feature\DefaultFeatureServices;
use Foundry\Feature\FeatureExecutor;
use Foundry\Feature\FeatureLoader;
use Foundry\Http\HttpKernel;
use Foundry\Http\RequestContext;
use Foundry\Observability\AuditRecorder;
use Foundry\Observability\StructuredLogger;
use Foundry\Observability\TraceContext;
use Foundry\Observability\TraceRecorder;
use Foundry\Queue\DefaultJobDispatcher;
use Foundry\Queue\JobDefinition;
use Foundry\Queue\JobRegistry;
use Foundry\Queue\RetryPolicy;
use Foundry\Queue\SyncQueueDriver;
use Foundry\Schema\JsonSchemaValidator;
use Foundry\Storage\LocalStorageDriver;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class RuntimeFactory
{
    public static function requestFromGlobals(): RequestContext
    {
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        $headers = self::headersFromGlobals();

        $query = is_array($_GET ?? null) ? $_GET : [];
        $body = self::bodyFromGlobals($headers);

        return new RequestContext($method, $path, $headers, $query, $body);
    }

    public static function httpKernel(Paths $paths): HttpKernel
    {
        $traceContext = new TraceContext();
        $traceRecorder = new TraceRecorder($traceContext);

        $connection = self::connection($paths);
        $featureLoader = new FeatureLoader($paths);
        $extensions = ExtensionRegistry::forPaths($paths);
        $permissions = self::permissionRegistry($paths);
        $queryRegistry = self::queryRegistry($paths);
        $cacheRegistry = self::cacheRegistry($paths);
        $eventRegistry = self::eventRegistry($paths);
        $jobRegistry = self::jobRegistry($paths);

        $services = new DefaultFeatureServices(
            new PdoQueryExecutor($connection, $queryRegistry),
            new CacheManager(new ArrayCacheStore(), $cacheRegistry),
            new DefaultJobDispatcher($jobRegistry, new SyncQueueDriver(), $traceRecorder),
            new DefaultEventDispatcher($eventRegistry, $traceRecorder),
            new LocalStorageDriver($paths->join('app/platform/storage/files')),
            $traceContext,
            new AIManager([
                'static' => new StaticAIProvider('static', ['content' => '', 'parsed' => []]),
            ])
        );

        $executor = new FeatureExecutor(
            $featureLoader,
            new AuthorizationEngine($permissions, ['bearer' => new HeaderTokenAuthenticator('x-user-id')]),
            new JsonSchemaValidator(),
            new TransactionManager($connection),
            $services,
            $traceRecorder,
            new AuditRecorder(),
            $paths,
            registeredInterceptors: $extensions->pipelineInterceptors(),
        );

        return new HttpKernel($executor, new StructuredLogger());
    }

    /**
     * @return array<string,mixed>
     */
    private static function loadIndex(Paths $paths, string $file): array
    {
        $path = $paths->join('app/.foundry/build/projections/' . $file);
        if (!is_file($path)) {
            $path = $paths->join('app/generated/' . $file);
        }

        if (!is_file($path)) {
            return [];
        }

        /** @var mixed $loaded */
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new FoundryError('RUNTIME_INDEX_INVALID', 'validation', ['path' => $path], 'Generated index must return an array.');
        }

        return $loaded;
    }

    private static function connection(Paths $paths): Connection
    {
        $dbDir = $paths->join('app/platform/storage');
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0777, true);
        }

        $pdo = new \PDO('sqlite:' . $dbDir . '/foundry.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new Connection($pdo);
    }

    private static function permissionRegistry(Paths $paths): PermissionRegistry
    {
        $registry = new PermissionRegistry();
        $index = self::loadIndex($paths, 'permission_index.php');

        foreach ($index as $feature => $row) {
            if (!is_string($feature) || !is_array($row)) {
                continue;
            }

            $permissions = array_values(array_map('strval', (array) ($row['permissions'] ?? [])));
            $registry->registerMany($permissions);
        }

        return $registry;
    }

    private static function queryRegistry(Paths $paths): QueryRegistry
    {
        $registry = new QueryRegistry();
        $index = self::loadIndex($paths, 'query_index.php');

        if ($index !== []) {
            foreach ($index as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $feature = (string) ($row['feature'] ?? '');
                $name = (string) ($row['name'] ?? '');
                $sql = (string) ($row['sql'] ?? '');
                if ($feature === '' || $name === '' || $sql === '') {
                    continue;
                }

                $registry->register(new QueryDefinition(
                    feature: $feature,
                    name: $name,
                    sql: $sql,
                    placeholders: array_values(array_map('strval', (array) ($row['placeholders'] ?? []))),
                ));
            }

            return $registry;
        }

        // Compatibility fallback for pre-compiler builds.
        $loader = new SqlFileLoader();
        $featureDirs = glob($paths->features() . '/*', GLOB_ONLYDIR) ?: [];
        sort($featureDirs);

        foreach ($featureDirs as $featureDir) {
            $feature = basename($featureDir);
            $sqlPath = $featureDir . '/queries.sql';
            if (!is_file($sqlPath)) {
                continue;
            }

            foreach ($loader->load($feature, $sqlPath) as $definition) {
                $registry->register($definition);
            }
        }

        return $registry;
    }

    private static function cacheRegistry(Paths $paths): CacheRegistry
    {
        $registry = new CacheRegistry();
        $index = self::loadIndex($paths, 'cache_index.php');

        foreach ($index as $key => $row) {
            if (!is_string($key) || !is_array($row)) {
                continue;
            }

            $registry->register(
                new CacheDefinition(
                    key: $key,
                    kind: (string) ($row['kind'] ?? 'computed'),
                    ttlSeconds: (int) ($row['ttl_seconds'] ?? 300),
                    invalidatedBy: array_values(array_map('strval', (array) ($row['invalidated_by'] ?? []))),
                )
            );
        }

        return $registry;
    }

    private static function eventRegistry(Paths $paths): EventRegistry
    {
        $registry = new EventRegistry();
        $index = self::loadIndex($paths, 'event_index.php');
        $emit = (array) ($index['emit'] ?? []);

        foreach ($emit as $eventName => $row) {
            if (!is_string($eventName) || !is_array($row)) {
                continue;
            }

            $registry->registerEvent(
                new EventDefinition(
                    name: $eventName,
                    schema: is_array($row['schema'] ?? null) ? $row['schema'] : [],
                )
            );
        }

        return $registry;
    }

    private static function jobRegistry(Paths $paths): JobRegistry
    {
        $registry = new JobRegistry();
        $index = self::loadIndex($paths, 'job_index.php');

        foreach ($index as $jobName => $row) {
            if (!is_string($jobName) || !is_array($row)) {
                continue;
            }

            $retry = (array) ($row['retry'] ?? []);
            $maxAttempts = max(1, (int) ($retry['max_attempts'] ?? 1));

            $backoff = array_values(array_map('intval', (array) ($retry['backoff_seconds'] ?? [1, 5, 30])));
            $backoff = array_values(array_filter($backoff, static fn (int $delay): bool => $delay >= 0));
            if ($backoff === []) {
                $backoff = [1];
            }

            $registry->register(
                new JobDefinition(
                    name: $jobName,
                    inputSchema: is_array($row['input_schema'] ?? null) ? $row['input_schema'] : [],
                    queue: (string) ($row['queue'] ?? 'default'),
                    retry: new RetryPolicy($maxAttempts, $backoff),
                    timeoutSeconds: max(1, (int) ($row['timeout_seconds'] ?? 60)),
                    idempotencyKey: isset($row['idempotency_key']) ? (string) $row['idempotency_key'] : null,
                )
            );
        }

        return $registry;
    }

    /**
     * @return array<string,string>
     */
    private static function headersFromGlobals(): array
    {
        $rawHeaders = function_exists('getallheaders') ? getallheaders() : [];
        if (!is_array($rawHeaders)) {
            return [];
        }

        $headers = [];
        foreach ($rawHeaders as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            $headers[$name] = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
        }

        return $headers;
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private static function bodyFromGlobals(array $headers): array
    {
        $contentType = '';
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $contentType = strtolower($value);
                break;
            }
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || $rawBody === '') {
            return is_array($_POST ?? null) ? $_POST : [];
        }

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);

            return is_array($decoded) ? $decoded : [];
        }

        parse_str($rawBody, $parsed);

        return is_array($parsed) && $parsed !== [] ? $parsed : (is_array($_POST ?? null) ? $_POST : []);
    }
}
