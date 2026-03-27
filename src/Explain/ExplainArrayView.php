<?php
declare(strict_types=1);

namespace Foundry\Explain;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Traversable;

/**
 * @implements ArrayAccess<string,mixed>
 * @implements IteratorAggregate<string,mixed>
 */
abstract class ExplainArrayView implements ArrayAccess, IteratorAggregate, JsonSerializable
{
    /**
     * @var array<string,mixed>
     */
    protected array $data = [];

    /**
     * @return array<string,mixed>
     */
    final public function toArray(): array
    {
        return $this->data;
    }

    final public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->data);
    }

    final public function offsetGet(mixed $offset): mixed
    {
        return $this->data[(string) $offset] ?? null;
    }

    final public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Explain views are immutable.');
    }

    final public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Explain views are immutable.');
    }

    /**
     * @return Traversable<string,mixed>
     */
    final public function getIterator(): Traversable
    {
        return new ArrayIterator($this->data);
    }

    /**
     * @return array<string,mixed>
     */
    final public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
