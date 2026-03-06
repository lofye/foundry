<?php
declare(strict_types=1);

namespace Forge\Webhook;

final class OutgoingWebhookDefinition
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly string $secret,
        public readonly array $headers = [],
    ) {
    }
}
