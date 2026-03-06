<?php
declare(strict_types=1);

namespace Forge\Tests\Unit;

use Forge\Cache\ArrayCacheStore;
use Forge\Cache\CacheDefinition;
use Forge\Cache\CacheKeyBuilder;
use Forge\Cache\CacheManager;
use Forge\Cache\CacheRegistry;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    public function test_key_builder_substitutes_placeholders(): void
    {
        $key = (new CacheKeyBuilder())->build('post:{slug}', ['slug' => 'hello world']);
        $this->assertSame('post:hello%20world', $key);
    }

    public function test_cache_manager_put_get_and_invalidate(): void
    {
        $registry = new CacheRegistry();
        $registry->register(new CacheDefinition('posts:list', 'computed', 60, ['publish_post']));

        $store = new ArrayCacheStore();
        $cache = new CacheManager($store, $registry);

        $cache->put('posts:list', ['a' => 1]);
        $this->assertSame(['a' => 1], $cache->get('posts:list'));

        $cache->invalidateByFeature('publish_post');
        $this->assertNull($cache->get('posts:list'));
    }
}
