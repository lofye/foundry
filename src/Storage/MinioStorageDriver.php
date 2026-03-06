<?php
declare(strict_types=1);

namespace Foundry\Storage;

use Foundry\Support\FoundryError;

/**
 * MinIO (S3-compatible) storage driver.
 *
 * This driver can be used with a provided client object (for tests/adapters),
 * or it can bootstrap an Aws\S3\S3Client when aws/aws-sdk-php is installed.
 */
final class MinioStorageDriver implements StorageDriver
{
    private readonly object $client;

    public function __construct(
        private readonly string $bucket,
        private readonly string $prefix = '',
        ?object $client = null,
        string $endpoint = 'http://127.0.0.1:9000',
        string $accessKey = 'minioadmin',
        string $secretKey = 'minioadmin',
        string $region = 'us-east-1',
    ) {
        if ($client !== null) {
            $this->client = $client;

            return;
        }

        if (!class_exists(\Aws\S3\S3Client::class)) {
            throw new FoundryError(
                'MINIO_SDK_MISSING',
                'runtime',
                [],
                'Install aws/aws-sdk-php or inject a compatible client.',
            );
        }

        /** @var object $sdkClient */
        $sdkClient = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ]);

        $this->client = $sdkClient;
    }

    #[\Override]
    public function write(string $path, string $content): FileDescriptor
    {
        try {
            $this->call('putObject', [[
                'Bucket' => $this->bucket,
                'Key' => $this->key($path),
                'Body' => $content,
                'ContentType' => 'application/octet-stream',
            ]]);
        } catch (\Throwable $e) {
            throw new FoundryError('MINIO_WRITE_FAILED', 'io', ['path' => $path], 'Failed to write object.', 0, $e);
        }

        return new FileDescriptor($path, strlen($content), 'application/octet-stream');
    }

    #[\Override]
    public function read(string $path): string
    {
        try {
            /** @var mixed $result */
            $result = $this->call('getObject', [[
                'Bucket' => $this->bucket,
                'Key' => $this->key($path),
            ]]);
        } catch (\Throwable $e) {
            if ($this->isNotFound($e)) {
                throw new FoundryError('MINIO_OBJECT_NOT_FOUND', 'not_found', ['path' => $path], 'Object not found.', 0, $e);
            }

            throw new FoundryError('MINIO_READ_FAILED', 'io', ['path' => $path], 'Failed to read object.', 0, $e);
        }

        $body = null;
        if (is_array($result)) {
            $body = $result['Body'] ?? null;
        } elseif ($result instanceof \ArrayAccess && isset($result['Body'])) {
            $body = $result['Body'];
        } elseif (is_object($result) && method_exists($result, 'get')) {
            /** @var mixed $resolvedBody */
            $resolvedBody = $result->get('Body');
            $body = $resolvedBody;
        }

        if (is_object($body) && method_exists($body, 'getContents')) {
            /** @var mixed $streamContent */
            $streamContent = $body->getContents();
            $body = $streamContent;
        } elseif (is_object($body) && method_exists($body, '__toString')) {
            $body = (string) $body;
        }

        if (!is_string($body)) {
            throw new FoundryError('MINIO_READ_INVALID', 'io', ['path' => $path], 'Object body is not readable.');
        }

        return $body;
    }

    #[\Override]
    public function exists(string $path): bool
    {
        try {
            $this->call('headObject', [[
                'Bucket' => $this->bucket,
                'Key' => $this->key($path),
            ]]);

            return true;
        } catch (\Throwable $e) {
            if ($this->isNotFound($e)) {
                return false;
            }

            throw new FoundryError('MINIO_EXISTS_FAILED', 'io', ['path' => $path], 'Failed to check object existence.', 0, $e);
        }
    }

    #[\Override]
    public function delete(string $path): void
    {
        try {
            $this->call('deleteObject', [[
                'Bucket' => $this->bucket,
                'Key' => $this->key($path),
            ]]);
        } catch (\Throwable $e) {
            if ($this->isNotFound($e)) {
                return;
            }

            throw new FoundryError('MINIO_DELETE_FAILED', 'io', ['path' => $path], 'Failed to delete object.', 0, $e);
        }
    }

    private function key(string $path): string
    {
        $path = ltrim($path, '/');
        $prefix = trim($this->prefix, '/');
        if ($prefix === '') {
            return $path;
        }

        return $prefix . '/' . $path;
    }

    /**
     * @param array<int,mixed> $args
     */
    private function call(string $method, array $args): mixed
    {
        if (!is_callable([$this->client, $method])) {
            throw new FoundryError('MINIO_CLIENT_INVALID', 'runtime', ['method' => $method], 'Configured MinIO client is missing required methods.');
        }

        return $this->client->{$method}(...$args);
    }

    private function isNotFound(\Throwable $e): bool
    {
        $code = (string) $e->getCode();
        if ($code === '404' || $code === 'NoSuchKey' || $code === 'NotFound') {
            return true;
        }

        $message = $e->getMessage();

        return str_contains($message, 'NoSuchKey')
            || str_contains($message, 'Not Found')
            || str_contains($message, '404');
    }
}
