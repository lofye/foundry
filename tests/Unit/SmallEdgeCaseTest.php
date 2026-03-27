<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Auth\PermissionRegistry;
use Foundry\Cache\CacheKeyBuilder;
use Foundry\DB\QueryDefinition;
use Foundry\DB\QueryRegistry;
use Foundry\Queue\RetryPolicy;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;
use Foundry\Verification\VerificationResult;
use PHPUnit\Framework\TestCase;

final class SmallEdgeCaseTest extends TestCase
{
    public function test_retry_policy_throws_for_invalid_inputs(): void
    {
        $this->expectException(FoundryError::class);
        new RetryPolicy(0, [1]);
    }

    public function test_retry_policy_delay_for_large_attempts(): void
    {
        $policy = new RetryPolicy(3, [1, 5, 30]);
        $this->assertSame(30, $policy->delayForAttempt(100));
    }

    public function test_cache_key_builder_missing_param_throws(): void
    {
        $this->expectException(FoundryError::class);
        (new CacheKeyBuilder())->build('post:{slug}', []);
    }

    public function test_query_registry_get_missing_throws_and_signatures_sort(): void
    {
        $registry = new QueryRegistry();
        $registry->register(new QueryDefinition('b', 'q', 'SELECT 1', []));
        $registry->register(new QueryDefinition('a', 'q', 'SELECT 1', []));

        $signatures = $registry->signatures();
        $this->assertSame('a', $signatures[0]['feature']);

        $this->expectException(FoundryError::class);
        $registry->get('x', 'y');
    }

    public function test_permission_registry_and_paths_helpers(): void
    {
        $permissions = new PermissionRegistry();
        $permissions->registerMany(['a', 'b', 'a']);
        $this->assertSame(['a', 'b'], $permissions->all());

        $paths = Paths::fromCwd('/tmp/project');
        $this->assertSame('/tmp/project/app', $paths->app());
        $this->assertSame('/tmp/project/bootstrap', $paths->bootstrap());
        $this->assertSame('/tmp/project/config', $paths->config());
        $this->assertSame('/tmp/project/database/migrations', $paths->migrations());
        $this->assertSame('/tmp/project/storage/files', $paths->storageFiles());
        $this->assertSame($paths->frameworkRoot() . '/examples', $paths->examples());
        $this->assertSame($paths->frameworkRoot() . '/stubs', $paths->stubs());
    }

    public function test_yaml_parse_file_missing_throws(): void
    {
        $this->expectException(FoundryError::class);
        Yaml::parseFile('/definitely/missing/file.yaml');
    }

    public function test_verification_result_to_array(): void
    {
        $result = new VerificationResult(false, ['e'], ['w']);
        $this->assertSame(['ok' => false, 'errors' => ['e'], 'warnings' => ['w']], $result->toArray());
    }
}
