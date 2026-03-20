<?php
declare(strict_types=1);

namespace Foundry\Schema;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;

final class JsonSchemaValidator implements SchemaValidator
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private array $cache = [];

    #[\Override]
    public function validate(array $data, string $schemaPath): ValidationResult
    {
        $schema = $this->loadSchema($schemaPath);
        $errors = [];
        $this->validateNode($data, $schema, '$', $errors);

        return $errors === [] ? ValidationResult::valid() : ValidationResult::invalid($errors);
    }

    /**
     * @return array<string,mixed>
     */
    private function loadSchema(string $schemaPath): array
    {
        if (isset($this->cache[$schemaPath])) {
            return $this->cache[$schemaPath];
        }

        if (!is_file($schemaPath)) {
            throw new FoundryError('SCHEMA_FILE_NOT_FOUND', 'not_found', ['path' => $schemaPath], 'Schema file not found.');
        }

        $content = file_get_contents($schemaPath);
        if ($content === false) {
            throw new FoundryError('SCHEMA_FILE_READ_ERROR', 'io', ['path' => $schemaPath], 'Failed to read schema file.');
        }

        $schema = Json::decodeAssoc($content);
        $this->cache[$schemaPath] = $schema;

        return $schema;
    }

    /**
     * @param mixed $value
     * @param array<string,mixed> $schema
     * @param array<int,ValidationError> $errors
     */
    private function validateNode(mixed $value, array $schema, string $path, array &$errors): void
    {
        if (isset($schema['type'])) {
            $this->validateType($value, $schema['type'], $path, $errors);
            if ($errors !== [] && end($errors)?->path === $path) {
                return;
            }
        }

        if (is_array($value) && ($this->isAssoc($value) || ($value === [] && $this->expectsObject($schema)))) {
            $this->validateObject($value, $schema, $path, $errors);
        }
    }

    /**
     * @param mixed $type
     * @param array<int,ValidationError> $errors
     */
    private function validateType(mixed $value, mixed $type, string $path, array &$errors): void
    {
        $types = is_array($type) ? $type : [$type];

        foreach ($types as $t) {
            if ($this->matchesType($value, (string) $t)) {
                return;
            }
        }

        $errors[] = new ValidationError($path, 'Type mismatch. Expected: ' . implode('|', array_map('strval', $types)));
    }

    /**
     * @param array<string,mixed> $value
     * @param array<string,mixed> $schema
     * @param array<int,ValidationError> $errors
     */
    private function validateObject(array $value, array $schema, string $path, array &$errors): void
    {
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($required as $name) {
            if (!array_key_exists((string) $name, $value)) {
                $errors[] = new ValidationError($path . '.' . (string) $name, 'Required property missing.');
            }
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($properties as $name => $propertySchema) {
            if (!array_key_exists($name, $value) || !is_array($propertySchema)) {
                continue;
            }

            $childPath = $path . '.' . $name;
            $this->validateNode($value[$name], $propertySchema, $childPath, $errors);

            if (is_string($value[$name] ?? null)) {
                $this->validateStringConstraints($value[$name], $propertySchema, $childPath, $errors);
            }

            if (array_key_exists('enum', $propertySchema) && is_array($propertySchema['enum'])) {
                if (!in_array($value[$name], $propertySchema['enum'], true)) {
                    $errors[] = new ValidationError($childPath, 'Value not in enum set.');
                }
            }
        }

        $allowAdditional = (bool) ($schema['additionalProperties'] ?? true);
        if (!$allowAdditional) {
            foreach ($value as $key => $_) {
                if (!array_key_exists($key, $properties)) {
                    $errors[] = new ValidationError($path . '.' . $key, 'Additional property is not allowed.');
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<int,ValidationError> $errors
     */
    private function validateStringConstraints(string $value, array $schema, string $path, array &$errors): void
    {
        if (isset($schema['minLength']) && is_int($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
            $errors[] = new ValidationError($path, 'String shorter than minLength.');
        }

        if (isset($schema['maxLength']) && is_int($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            $errors[] = new ValidationError($path, 'String longer than maxLength.');
        }

        if (isset($schema['pattern']) && is_string($schema['pattern'])) {
            $regex = '/' . str_replace('/', '\\/', $schema['pattern']) . '/';
            if (@preg_match($regex, $value) !== 1) {
                $errors[] = new ValidationError($path, 'String does not match pattern.');
            }
        }

        if (($schema['format'] ?? null) === 'date-time' && strtotime($value) === false) {
            $errors[] = new ValidationError($path, 'String is not a valid date-time.');
        }
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || $this->isAssoc($value)),
            'array' => is_array($value) && ($value === [] || !$this->isAssoc($value)),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function expectsObject(array $schema): bool
    {
        $types = $schema['type'] ?? null;
        if ($types === null) {
            return false;
        }

        $types = is_array($types) ? $types : [$types];

        return in_array('object', array_map('strval', $types), true);
    }

    /**
     * @param array<mixed> $array
     */
    private function isAssoc(array $array): bool
    {
        return array_is_list($array) === false;
    }
}
