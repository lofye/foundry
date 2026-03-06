<?php
declare(strict_types=1);

namespace Forge\Webhook;

final class WebhookVerifier
{
    public function __construct(private readonly WebhookSigner $signer = new WebhookSigner())
    {
    }

    public function verify(string $payload, string $secret, string $signature): bool
    {
        $expected = $this->signer->sign($payload, $secret);

        return hash_equals($expected, $signature);
    }
}
