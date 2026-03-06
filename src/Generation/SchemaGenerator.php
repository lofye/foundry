<?php
declare(strict_types=1);

namespace Forge\Generation;

final class SchemaGenerator
{
    /**
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    public function fromFieldSpec(string $title, array $spec): array
    {
        $fields = is_array($spec['fields'] ?? null) ? $spec['fields'] : [];

        $required = [];
        $properties = [];

        foreach ($fields as $name => $fieldSpec) {
            if (!is_array($fieldSpec)) {
                continue;
            }

            $properties[$name] = $this->mapField($fieldSpec);
            if ((bool) ($fieldSpec['required'] ?? false)) {
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
