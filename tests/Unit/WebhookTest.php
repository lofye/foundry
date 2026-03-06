<?php
declare(strict_types=1);

namespace Forge\Tests\Unit;

use Forge\Webhook\WebhookSigner;
use Forge\Webhook\WebhookVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    public function test_sign_and_verify_payload(): void
    {
        $signer = new WebhookSigner();
        $payload = '{"a":1}';
        $secret = 'secret';
        $signature = $signer->sign($payload, $secret);

        $verifier = new WebhookVerifier($signer);
        $this->assertTrue($verifier->verify($payload, $secret, $signature));
        $this->assertFalse($verifier->verify($payload, $secret, 'bad'));
    }
}
