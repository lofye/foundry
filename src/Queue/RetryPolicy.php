<?php
declare(strict_types=1);

namespace Forge\Queue;

use Forge\Support\ForgeError;

final class RetryPolicy
{
    /**
     * @param array<int,int> $backoffSeconds
     */
    public function __construct(
        public readonly int $maxAttempts,
        public readonly array $backoffSeconds,
    ) {
        if ($this->maxAttempts < 1) {
            throw new ForgeError('RETRY_MAX_ATTEMPTS_INVALID', 'validation', ['max_attempts' => $this->maxAttempts], 'Max attempts must be >= 1.');
        }

        if ($this->backoffSeconds === []) {
            throw new ForgeError('RETRY_BACKOFF_INVALID', 'validation', [], 'Backoff must not be empty.');
        }
    }

    public function delayForAttempt(int $attempt): int
    {
        $index = max(0, $attempt - 1);

        return $this->backoffSeconds[min($index, count($this->backoffSeconds) - 1)];
    }
}
