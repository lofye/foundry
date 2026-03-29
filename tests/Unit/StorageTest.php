<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Storage\InMemoryStorageDriver;
use Foundry\Storage\LocalStorageDriver;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    public function test_local_storage_read_write_delete(): void
    {
        $root = sys_get_temp_dir() . '/foundry-storage-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        $driver = new LocalStorageDriver($root);
        $driver->write('foo/bar.txt', 'hello');

        $this->assertTrue($driver->exists('foo/bar.txt'));
        $this->assertSame('hello', $driver->read('foo/bar.txt'));

        $driver->delete('foo/bar.txt');
        $this->assertFalse($driver->exists('foo/bar.txt'));

        @unlink($root . '/foo/bar.txt');
        @rmdir($root . '/foo');
        @rmdir($root);
    }

    public function test_in_memory_storage_read_write_delete(): void
    {
        $driver = new InMemoryStorageDriver();
        $driver->write('x.txt', 'value');

        $this->assertTrue($driver->exists('x.txt'));
        $this->assertSame('value', $driver->read('x.txt'));

        $driver->delete('x.txt');
        $this->assertFalse($driver->exists('x.txt'));
    }
}
