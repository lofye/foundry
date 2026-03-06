<?php
declare(strict_types=1);

namespace Forge\Webhook;

final class IncomingWebhookDefinition
{
    /**
     * @param array<string,mixed> $schema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly string $secret,
        public readonly array $schema,
    ) {
    }
}
