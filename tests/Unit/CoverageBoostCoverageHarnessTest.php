<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Auth\AuthContext;
use Foundry\Auth\HeaderTokenAuthenticator;
use Foundry\Auth\RolePolicyRegistry;
use Foundry\Billing\BillingPlanRegistry;
use Foundry\Cache\ArrayCacheStore;
use Foundry\Cache\CacheDefinition;
use Foundry\Cache\CacheManager;
use Foundry\Cache\CacheRegistry;
use Foundry\Compiler\Migration\ManifestVersionResolver;
use Foundry\Config\ConfigRepository;
use Foundry\DB\DatabaseManager;
use Foundry\DB\QueryDefinition;
use Foundry\DB\SqlFileLoader;
use Foundry\Events\EventDefinition;
use Foundry\Events\EventRegistry;
use Foundry\Feature\FeatureDefinition;
use Foundry\Http\RequestContext;
use Foundry\Notifications\NotificationTemplateRenderer;
use Foundry\Queue\DefaultJobDispatcher;
use Foundry\Queue\JobDefinition;
use Foundry\Queue\JobRegistry;
use Foundry\Queue\QueueDriver;
use Foundry\Queue\RetryPolicy;
use Foundry\Schema\Schema;
use Foundry\Schema\SchemaRegistry;
use Foundry\Search\SearchAdapter;
use Foundry\Search\SearchManager;
use Foundry\Search\SqlSearchAdapter;
use Foundry\Storage\LocalStorageDriver;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use Foundry\Verification\MigrationsVerifier;
use PHPUnit\Framework\TestCase;

final class CoverageBoostCoverageHarnessTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_auth_environment_and_config_accessors_cover_missing_lines(): void
    {
        $guest = AuthContext::guest();
        $this->assertSame([], $guest->roles());
        $this->assertSame([], $guest->metadata());

        $feature = new FeatureDefinition(
            name: 'publish_post',
            kind: 'http',
            description: 'desc',
            route: ['method' => 'GET', 'path' => '/posts'],
            inputSchemaPath: 'in',
            outputSchemaPath: 'out',
            auth: ['permissions' => ['posts.view']],
            database: [],
            cache: [],
            events: [],
            jobs: [],
            rateLimit: [],
            tests: [],
            llm: [],
            basePath: '/tmp',
        );

        $authenticator = new HeaderTokenAuthenticator();
        $this->assertSame('bearer', $authenticator->strategy());

        $auth = $authenticator->authenticate(
            new RequestContext('GET', '/posts', ['x-user-id' => 'u-1']),
            $feature,
        );

        $this->assertSame(['posts.view'], $auth->permissions());
        $this->assertSame(['source' => 'header'], $auth->metadata());

        $config = new ConfigRepository();
        $config->set('app.name', 'Foundry');
        $this->assertSame(['app' => ['name' => 'Foundry']], $config->all());
    }

    public function test_database_schema_event_and_cache_registries_cover_not_found_and_sorting_paths(): void
    {
        $database = new DatabaseManager();
        try {
            $database->connection('analytics');
            self::fail('Expected missing database connection.');
        } catch (FoundryError $error) {
            self::assertSame('DB_CONNECTION_NOT_FOUND', $error->errorCode);
        }

        $schemas = new SchemaRegistry();
        $schemas->register(new Schema('b.json', ['type' => 'object']));
        $schemas->register(new Schema('a.json', ['type' => 'object']));
        $this->assertSame('a.json', $schemas->get('a.json')->path);
        $this->assertSame(['a.json', 'b.json'], array_keys($schemas->all()));

        $events = new EventRegistry();
        $events->registerEvent(new EventDefinition('post.updated', ['type' => 'object']));
        $events->registerEvent(new EventDefinition('post.created', ['type' => 'object']));
        try {
            $events->event('missing');
            self::fail('Expected missing event.');
        } catch (FoundryError $error) {
            self::assertSame('EVENT_NOT_FOUND', $error->errorCode);
        }
        $this->assertSame(['post.created', 'post.updated'], array_keys($events->allEvents()));

        $cacheRegistry = new CacheRegistry();
        $cacheRegistry->register(new CacheDefinition('posts:detail', 'computed', 60, ['publish_post']));
        $cacheRegistry->register(new CacheDefinition('posts:list', 'computed', 60, ['publish_post']));
        try {
            $cacheRegistry->get('missing');
            self::fail('Expected missing cache entry.');
        } catch (FoundryError $error) {
            self::assertSame('CACHE_ENTRY_NOT_FOUND', $error->errorCode);
        }
        $this->assertSame(['posts:detail', 'posts:list'], array_keys($cacheRegistry->all()));
        $this->assertCount(2, $cacheRegistry->invalidatedBy('publish_post'));
    }

    public function test_retry_manifest_cache_manager_and_search_paths_cover_missing_branches(): void
    {
        try {
            new RetryPolicy(2, []);
            self::fail('Expected empty backoff failure.');
        } catch (FoundryError $error) {
            self::assertSame('RETRY_BACKOFF_INVALID', $error->errorCode);
        }

        $resolver = new ManifestVersionResolver(4);
        $this->assertSame(4, $resolver->currentFeatureVersion());
        $this->assertSame(3, $resolver->resolveFeatureVersion(['version' => '3']));
        $this->assertSame(1, $resolver->resolveFeatureVersion(['version' => 'next']));
        $this->assertTrue($resolver->isFeatureOutdated(['version' => 2]));

        $store = new ArrayCacheStore();
        $store->put('stale', 'value', -1);
        $this->assertFalse($store->has('stale'));
        $this->assertNull($store->get('stale'));

        $cacheRegistry = new CacheRegistry();
        $manager = new CacheManager($store, $cacheRegistry);
        $manager->put('posts:{id}', ['id' => 1], ['id' => 1], 60);
        $manager->forget('posts:{id}', ['id' => 1]);
        $this->assertNull($manager->get('posts:{id}', ['id' => 1]));

        $customAdapter = new class implements SearchAdapter {
            public function id(): string
            {
                return 'custom';
            }

            public function search(array $rows, string $query, array $fields, array $filters = []): array
            {
                return [['query' => $query, 'fields' => $fields, 'filters' => $filters, 'rows' => $rows]];
            }
        };

        $search = new SearchManager([$customAdapter]);
        $customResult = $search->query('custom', [['id' => '1']], 'hello', ['title'], ['status' => 'published']);
        $this->assertSame('hello', $customResult[0]['query']);

        $sql = new SqlSearchAdapter();
        $matches = $sql->search(
            ['skip-me', ['title' => 'Alpha', 'status' => 'published'], ['title' => 'Beta', 'status' => 'draft']],
            '',
            ['title'],
            ['status' => 'published'],
        );
        $this->assertCount(1, $matches);
        $this->assertSame('Alpha', $matches[0]['title']);
    }

    public function test_projection_registries_and_migrations_cover_missing_projection_paths(): void
    {
        $paths = Paths::fromCwd($this->project->root);

        $billingProjection = $this->project->root . '/app/.foundry/build/projections';
        mkdir($billingProjection, 0777, true);
        file_put_contents($billingProjection . '/billing_index.php', "<?php\nreturn 'invalid';\n");

        $billing = new BillingPlanRegistry($paths);
        $this->assertSame([], $billing->all());
        $this->assertSame([], $billing->provider('stripe'));

        $roles = new RolePolicyRegistry($paths);
        $this->assertSame([], $roles->roles());
        $this->assertSame([], $roles->policies());

        $migrations = new MigrationsVerifier($paths);
        $result = $migrations->verify();
        $this->assertTrue($result->ok);
        $this->assertSame([], $result->errors);
    }

    public function test_sql_loader_storage_notification_and_job_dispatcher_cover_failure_branches(): void
    {
        $loader = new SqlFileLoader();
        $queries = $loader->parse('publish_post', "-- name: empty\n\n-- name: real\nSELECT 1;\n");
        $this->assertCount(1, $queries);
        $this->assertInstanceOf(QueryDefinition::class, $queries[0]);

        $sqlPath = $this->project->root . '/restricted.sql';
        file_put_contents($sqlPath, "-- name: read_me\nSELECT 1;\n");

        $templatePath = $this->project->root . '/template.txt';
        file_put_contents($templatePath, 'Hello {{name}}');

        chmod($sqlPath, 0000);
        chmod($templatePath, 0000);
        clearstatcache();

        try {
            $this->suppressWarnings(function () use ($loader, $sqlPath): void {
                try {
                    $loader->load('publish_post', $sqlPath);
                    self::fail('Expected unreadable SQL file failure.');
                } catch (FoundryError $error) {
                    self::assertSame('SQL_FILE_READ_ERROR', $error->errorCode);
                }
            });

            $renderer = new NotificationTemplateRenderer();
            $this->suppressWarnings(function () use ($renderer, $templatePath): void {
                try {
                    $renderer->render($templatePath, ['name' => 'Ada']);
                    self::fail('Expected unreadable template failure.');
                } catch (FoundryError $error) {
                    self::assertSame('NOTIFICATION_TEMPLATE_INVALID', $error->errorCode);
                }
            });
        } finally {
            chmod($sqlPath, 0644);
            chmod($templatePath, 0644);
        }

        $storageRoot = $this->project->root . '/storage';
        mkdir($storageRoot . '/existing-dir', 0777, true);
        $storage = new LocalStorageDriver($storageRoot);

        $this->suppressWarnings(function () use ($storage): void {
            try {
                $storage->write('existing-dir', 'payload');
                self::fail('Expected write failure when target is a directory.');
            } catch (FoundryError $error) {
                self::assertSame('STORAGE_WRITE_FAILED', $error->errorCode);
            }
        });

        $this->suppressWarnings(function () use ($storage): void {
            try {
                $storage->read('missing-file.txt');
                self::fail('Expected read failure when target is missing.');
            } catch (FoundryError $error) {
                self::assertSame('STORAGE_READ_FAILED', $error->errorCode);
            }
        });

        $registry = new JobRegistry();
        $registry->register(new JobDefinition(
            name: 'notify_followers',
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['post_id'],
                'properties' => [
                    'post_id' => ['type' => 'string'],
                ],
            ],
            queue: 'default',
            retry: new RetryPolicy(2, [1, 2]),
            timeoutSeconds: 30,
        ));

        $dispatcher = new DefaultJobDispatcher($registry, new class implements QueueDriver {
            public function enqueue(string $queue, string $jobName, array $payload): void
            {
            }

            public function dequeue(string $queue): ?array
            {
                return null;
            }

            public function inspect(string $queue): array
            {
                return [];
            }
        });

        try {
            $dispatcher->dispatch('notify_followers', []);
            self::fail('Expected invalid job payload failure.');
        } catch (FoundryError $error) {
            self::assertSame('JOB_PAYLOAD_INVALID', $error->errorCode);
        }
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function suppressWarnings(callable $callback): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
