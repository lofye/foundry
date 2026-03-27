<?php

declare(strict_types=1);

namespace Foundry\AI;

interface AIProvider
{
    public function name(): string;

    public function complete(AIRequest $request): AIResponse;
}
