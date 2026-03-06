<?php
declare(strict_types=1);

namespace Foundry\Observability;

use Foundry\Support\Uuid;

final class TraceContext
{
    private string $traceId;

    public function __construct(?string $traceId = null)
    {
        $this->traceId = $traceId ?? Uuid::v4();
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
