<?php
declare(strict_types=1);

namespace Forge\Http;

use Forge\Feature\FeatureExecutor;
use Forge\Observability\Logger;
use Forge\Support\ForgeError;

final class HttpKernel
{
    public function __construct(
        private readonly FeatureExecutor $executor,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:array<string,mixed>}
     */
    public function handle(RequestContext $request): array
    {
        try {
            $payload = $this->executor->executeHttp($request);

            return [
                'status' => 200,
                'headers' => ['content-type' => 'application/json'],
                'body' => $payload,
            ];
        } catch (ForgeError $error) {
            $this->logger->log('error', 'request_failed', $error->toArray());

            return [
                'status' => $this->statusFor($error),
                'headers' => ['content-type' => 'application/json'],
                'body' => $error->toArray(),
            ];
        } catch (\Throwable $e) {
            $this->logger->log('critical', 'request_failed', ['message' => $e->getMessage()]);

            return [
                'status' => 500,
                'headers' => ['content-type' => 'application/json'],
                'body' => [
                    'error' => [
                        'code' => 'UNHANDLED_EXCEPTION',
                        'category' => 'runtime',
                        'message' => 'Unhandled exception.',
                        'details' => ['exception' => $e::class],
                    ],
                ],
            ];
        }
    }

    private function statusFor(ForgeError $error): int
    {
        return match ($error->category) {
            'authorization' => 403,
            'authentication' => 401,
            'not_found' => 404,
            'validation' => 422,
            default => 500,
        };
    }
}
