<?php

declare(strict_types=1);

namespace Foundry\Support;

final class Clock
{
    public function __construct(
        private readonly ?\DateTimeImmutable $fixedNow = null,
    ) {}

    public function now(): \DateTimeImmutable
    {
        return $this->fixedNow ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function nowIso8601(): string
    {
        return $this->now()->format(\DateTimeInterface::ATOM);
    }
}
