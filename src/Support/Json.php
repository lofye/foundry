<?php

declare(strict_types=1);

namespace Foundry\Support;

final class Json
{
    /**
     * @return array<string,mixed>
     */
    public static function decodeAssoc(string $json): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FoundryError('JSON_PARSE_ERROR', 'parsing', ['raw' => $json], $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new FoundryError('JSON_OBJECT_REQUIRED', 'validation', [], 'JSON root must be an object.');
        }

        return $decoded;
    }

    public static function encode(mixed $value, bool $pretty = false): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0),
            );
        } catch (\JsonException $e) {
            throw new FoundryError('JSON_ENCODE_ERROR', 'serialization', [], $e->getMessage(), 0, $e);
        }
    }
}
