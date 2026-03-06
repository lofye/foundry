<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Storage\MinioStorageDriver;
use Foundry\Support\FoundryError;
use PHPUnit\Framework\TestCase;

final class MinioStorageDriverTest extends TestCase
{
    public function test_write_read_exists_and_delete_with_injected_client(): void
    {
        $client = new FakeMinioClient();
        $driver = new MinioStorageDriver('test-bucket', 'prefix', $client);

        $descriptor = $driver->write('docs/a.txt', 'hello');
        $this->assertSame('docs/a.txt', $descriptor->path);
        $this->assertSame(5, $descriptor->size);
        $this->assertTrue($driver->exists('docs/a.txt'));
        $this->assertSame('hello', $driver->read('docs/a.txt'));

        $driver->delete('docs/a.txt');
        $this->assertFalse($driver->exists('docs/a.txt'));
    }

    public function test_read_accepts_stream_like_body(): void
    {
        $client = new FakeMinioClient();
        $driver = new MinioStorageDriver('test-bucket', '', $client);

        $client->objects['x.txt'] = new FakeBodyStream('streamed');

        $this->assertSame('streamed', $driver->read('x.txt'));
    }

    public function test_read_throws_not_found_for_missing_object(): void
    {
        $driver = new MinioStorageDriver('test-bucket', '', new FakeMinioClient());

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Object not found.');
        $driver->read('missing.txt');
    }

    public function test_read_wraps_non_not_found_failures_and_invalid_bodies(): void
    {
        $client = new FakeMinioClient();
        $driver = new MinioStorageDriver('test-bucket', '', $client);

        $client->getException = new \RuntimeException('upstream unavailable', 500);
        try {
            $driver->read('x.txt');
            $this->fail('Expected wrapped read failure');
        } catch (FoundryError $e) {
            $this->assertSame('MINIO_READ_FAILED', $e->errorCode);
        }

        $client->getException = null;
        $client->objects['bad.txt'] = 123;
        try {
            $driver->read('bad.txt');
            $this->fail('Expected invalid body failure');
        } catch (FoundryError $e) {
            $this->assertSame('MINIO_READ_INVALID', $e->errorCode);
        }
    }

    public function test_exists_throws_for_non_not_found_errors(): void
    {
        $client = new FakeMinioClient();
        $client->headException = new \RuntimeException('backend down', 500);
        $driver = new MinioStorageDriver('test-bucket', '', $client);

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Failed to check object existence.');
        $driver->exists('x.txt');
    }

    public function test_write_delete_and_read_wrap_client_failures(): void
    {
        $client = new FakeMinioClient();
        $driver = new MinioStorageDriver('test-bucket', '', $client);

        $client->putException = new \RuntimeException('write fail');
        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Failed to write object.');
        $driver->write('x.txt', 'v');
    }

    public function test_delete_ignores_not_found_and_wraps_other_errors(): void
    {
        $client = new FakeMinioClient();
        $driver = new MinioStorageDriver('test-bucket', '', $client);

        $client->deleteException = new \RuntimeException('NoSuchKey', 404);
        $driver->delete('missing.txt');
        $this->assertTrue(true);

        $client->deleteException = new \RuntimeException('delete fail', 500);
        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Failed to delete object.');
        $driver->delete('x.txt');
    }

    public function test_constructor_requires_sdk_or_client(): void
    {
        if (class_exists(\Aws\S3\S3Client::class)) {
            self::markTestSkipped('aws/aws-sdk-php is installed in this environment.');
        }

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Install aws/aws-sdk-php or inject a compatible client.');
        new MinioStorageDriver('bucket');
    }

    public function test_invalid_client_throws_meaningful_error(): void
    {
        $driver = new MinioStorageDriver('bucket', '', new \stdClass());

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Failed to check object existence.');
        $driver->exists('x.txt');
    }
}

final class FakeMinioClient
{
    /**
     * @var array<string,mixed>
     */
    public array $objects = [];

    public ?\Throwable $putException = null;
    public ?\Throwable $getException = null;
    public ?\Throwable $headException = null;
    public ?\Throwable $deleteException = null;

    /**
     * @param array{Bucket:string,Key:string,Body:string,ContentType:string} $args
     */
    public function putObject(array $args): void
    {
        if ($this->putException !== null) {
            throw $this->putException;
        }

        $this->objects[$args['Key']] = $args['Body'];
    }

    /**
     * @param array{Bucket:string,Key:string} $args
     * @return array{Body:string|FakeBodyStream}
     */
    public function getObject(array $args): array
    {
        if ($this->getException !== null) {
            throw $this->getException;
        }

        if (!array_key_exists($args['Key'], $this->objects)) {
            throw new \RuntimeException('NoSuchKey', 404);
        }

        return ['Body' => $this->objects[$args['Key']]];
    }

    /**
     * @param array{Bucket:string,Key:string} $args
     */
    public function headObject(array $args): void
    {
        if ($this->headException !== null) {
            throw $this->headException;
        }

        if (!array_key_exists($args['Key'], $this->objects)) {
            throw new \RuntimeException('Not Found', 404);
        }
    }

    /**
     * @param array{Bucket:string,Key:string} $args
     */
    public function deleteObject(array $args): void
    {
        if ($this->deleteException !== null) {
            throw $this->deleteException;
        }

        if (!array_key_exists($args['Key'], $this->objects)) {
            throw new \RuntimeException('NoSuchKey', 404);
        }

        unset($this->objects[$args['Key']]);
    }
}

final class FakeBodyStream
{
    public function __construct(private readonly string $content)
    {
    }

    public function getContents(): string
    {
        return $this->content;
    }
}
