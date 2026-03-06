<?php
declare(strict_types=1);

namespace Forge\AI;

interface AIProvider
{
    public function name(): string;

    public function complete(AIRequest $request): AIResponse;
}
