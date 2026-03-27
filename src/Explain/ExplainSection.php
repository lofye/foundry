<?php
declare(strict_types=1);

namespace Foundry\Explain;

final class ExplainSection extends ExplainArrayView
{
    /**
     * @param array<string,mixed> $section
     */
    public static function fromArray(array $section): self
    {
        $id = trim((string) ($section['id'] ?? ''));
        $title = trim((string) ($section['title'] ?? 'Details'));
        $items = is_array($section['items'] ?? null) ? $section['items'] : [];
        $shape = trim((string) ($section['shape'] ?? ''));

        return new self(
            $id,
            $title,
            $shape !== '' ? $shape : self::inferShape($items),
            $items,
        );
    }

    /**
     * @param array<string,mixed> $items
     */
    public function __construct(string $id, string $title, string $shape, array $items)
    {
        $this->data = [
            'id' => $id,
            'title' => $title,
            'shape' => $shape,
            'items' => $items,
        ];
    }

    public function id(): string
    {
        return (string) $this->data['id'];
    }

    public function title(): string
    {
        return (string) $this->data['title'];
    }

    public function shape(): string
    {
        return (string) $this->data['shape'];
    }

    /**
     * @return array<string,mixed>
     */
    public function items(): array
    {
        return is_array($this->data['items']) ? $this->data['items'] : [];
    }

    public function isRenderable(): bool
    {
        $items = $this->items();
        if ($items === []) {
            return false;
        }

        return match ($this->shape()) {
            'string_list', 'row_list' => array_values($items) !== [],
            default => $items !== [],
        };
    }

    /**
     * @param array<string,mixed> $items
     */
    public static function inferShape(array $items): string
    {
        if (!array_is_list($items)) {
            return 'key_value';
        }

        $hasArrays = false;
        foreach ($items as $item) {
            if (is_array($item)) {
                $hasArrays = true;
                continue;
            }

            if (!is_scalar($item) && $item !== null) {
                return 'key_value';
            }
        }

        return $hasArrays ? 'row_list' : 'string_list';
    }
}
