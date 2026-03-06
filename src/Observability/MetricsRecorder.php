<?php
declare(strict_types=1);

namespace Forge\Observability;

final class MetricsRecorder
{
    /**
     * @var array<string,float>
     */
    private array $counters = [];

    /**
     * @var array<string,float>
     */
    private array $timings = [];

    public function increment(string $name, float $value = 1.0): void
    {
        $this->counters[$name] = ($this->counters[$name] ?? 0.0) + $value;
    }

    public function observe(string $name, float $value): void
    {
        $this->timings[$name] = $value;
    }

    /**
     * @return array<string,float>
     */
    public function counters(): array
    {
        ksort($this->counters);

        return $this->counters;
    }

    /**
     * @return array<string,float>
     */
    public function timings(): array
    {
        ksort($this->timings);

        return $this->timings;
    }
}
