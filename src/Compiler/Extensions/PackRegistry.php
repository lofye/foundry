<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

final class PackRegistry
{
    /**
     * @var array<string,PackDefinition>
     */
    private array $packs = [];

    /**
     * @param array<int,PackDefinition> $packs
     */
    public function __construct(array $packs = [])
    {
        foreach ($packs as $pack) {
            $this->register($pack);
        }
    }

    public function register(PackDefinition $pack): void
    {
        $this->packs[$pack->name] = $pack;
        ksort($this->packs);
    }

    public function has(string $name): bool
    {
        return isset($this->packs[$name]);
    }

    public function get(string $name): ?PackDefinition
    {
        return $this->packs[$name] ?? null;
    }

    /**
     * @return array<int,PackDefinition>
     */
    public function all(): array
    {
        return array_values($this->packs);
    }

    /**
     * @return array<int,string>
     */
    public function providedCapabilities(): array
    {
        $capabilities = [];
        foreach ($this->packs as $pack) {
            foreach ($pack->providedCapabilities as $capability) {
                $capabilities[] = (string) $capability;
            }
        }

        $capabilities = array_values(array_filter($capabilities, static fn (string $value): bool => $value !== ''));
        $capabilities = array_values(array_unique($capabilities));
        sort($capabilities);

        return $capabilities;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function inspectRows(): array
    {
        return array_values(array_map(
            static fn (PackDefinition $pack): array => $pack->toArray(),
            $this->all(),
        ));
    }
}
