<?php

declare(strict_types=1);

namespace Foundry\Generation;

final class SchemaGenerator
{
    /**
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    public function fromFieldDefinition(string $title, array $definition): array
    {
        $fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];

        $required = [];
        $properties = [];

        foreach ($fields as $name => $fieldDefinition) {
            if (!is_array($fieldDefinition)) {
                continue;
            }

            $properties[$name] = $this->mapField($fieldDefinition);
            if ((bool) ($fieldDefinition['required'] ?? false)) {
                $required[] = $name;
            }
        }

        ksort($properties);

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => $title,
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_values($required),
            'properties' => $properties,
        ];
    }

    /**
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private function mapField(array $field): array
    {
        $schema = [
            'type' => (string) ($field['type'] ?? 'string'),
        ];

        foreach (['minLength', 'maxLength', 'pattern', 'format', 'enum', 'default'] as $key) {
            if (array_key_exists($key, $field)) {
                $schema[$key] = $field[$key];
            }
        }

        return $schema;
    }
}
