<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Foundry\Storage\MinioStorageDriver;
use Foundry\Support\FoundryError;
use PHPUnit\Framework\TestCase;

final class MinioStorageDriverIntegrationTest extends TestCase
{
    public function test_write_read_exists_and_delete_against_live_minio(): void
    {
        $config = $this->minioConfig();
        $this->ensureBucket($config);

        $prefix = 'foundry-int-' . bin2hex(random_bytes(4));
        $driver = new MinioStorageDriver(
            bucket: $config['bucket'],
            prefix: $prefix,
            endpoint: $config['endpoint'],
            accessKey: $config['access'],
            secretKey: $config['secret'],
            region: $config['region'],
        );

        $path = 'docs/' . bin2hex(random_bytes(4)) . '.txt';
        $descriptor = $driver->write($path, 'hello-minio');

        $this->assertSame($path, $descriptor->path);
        $this->assertTrue($driver->exists($path));
        $this->assertSame('hello-minio', $driver->read($path));

        $driver->delete($path);
        $this->assertFalse($driver->exists($path));
    }

    public function test_missing_object_returns_not_found_error(): void
    {
        $config = $this->minioConfig();
        $this->ensureBucket($config);

        $driver = new MinioStorageDriver(
            bucket: $config['bucket'],
            prefix: 'foundry-int-' . bin2hex(random_bytes(4)),
            endpoint: $config['endpoint'],
            accessKey: $config['access'],
            secretKey: $config['secret'],
            region: $config['region'],
        );

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Object not found.');
        $driver->read('missing/' . bin2hex(random_bytes(4)) . '.txt');
    }

    /**
     * @return array{endpoint:string,access:string,secret:string,bucket:string,region:string}
     */
    private function minioConfig(): array
    {
        if (!class_exists(S3Client::class)) {
            self::markTestSkipped('aws/aws-sdk-php is not installed.');
        }

        return [
            'endpoint' => (string) (getenv('FOUNDRY_TEST_MINIO_ENDPOINT') ?: 'http://127.0.0.1:9100'),
            'access' => (string) (getenv('FOUNDRY_TEST_MINIO_ACCESS_KEY') ?: 'foundry'),
            'secret' => (string) (getenv('FOUNDRY_TEST_MINIO_SECRET_KEY') ?: 'foundry-dev-secret'),
            'bucket' => (string) (getenv('FOUNDRY_TEST_MINIO_BUCKET') ?: 'foundry-dev'),
            'region' => (string) (getenv('FOUNDRY_TEST_MINIO_REGION') ?: 'us-east-1'),
        ];
    }

    /**
     * @param array{endpoint:string,access:string,secret:string,bucket:string,region:string} $config
     */
    private function ensureBucket(array $config): void
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => $config['region'],
            'endpoint' => $config['endpoint'],
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $config['access'],
                'secret' => $config['secret'],
            ],
        ]);

        try {
            $client->headBucket(['Bucket' => $config['bucket']]);

            return;
        } catch (AwsException $e) {
            $code = (string) ($e->getAwsErrorCode() ?? '');
            $status = (int) ($e->getStatusCode() ?? 0);

            if ($code === 'NoSuchBucket' || $status === 404) {
                try {
                    $client->createBucket(['Bucket' => $config['bucket']]);
                    $client->waitUntil('BucketExists', ['Bucket' => $config['bucket']]);

                    return;
                } catch (\Throwable $createError) {
                    self::markTestSkipped('MinIO bucket setup failed: ' . $createError->getMessage());
                }
            }

            self::markTestSkipped('MinIO not reachable or credentials invalid: ' . $e->getMessage());
        } catch (\Throwable $e) {
            self::markTestSkipped('MinIO not reachable: ' . $e->getMessage());
        }
    }
}
