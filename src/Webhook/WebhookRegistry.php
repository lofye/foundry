<?php
declare(strict_types=1);

namespace Foundry\Webhook;

final class WebhookRegistry
{
    /**
     * @var array<string,IncomingWebhookDefinition>
     */
    private array $incoming = [];

    /**
     * @var array<string,OutgoingWebhookDefinition>
     */
    private array $outgoing = [];

    public function registerIncoming(IncomingWebhookDefinition $definition): void
    {
        $this->incoming[$definition->name] = $definition;
    }

    public function registerOutgoing(OutgoingWebhookDefinition $definition): void
    {
        $this->outgoing[$definition->name] = $definition;
    }

    public function incoming(string $name): ?IncomingWebhookDefinition
    {
        return $this->incoming[$name] ?? null;
    }

    public function outgoing(string $name): ?OutgoingWebhookDefinition
    {
        return $this->outgoing[$name] ?? null;
    }
}
