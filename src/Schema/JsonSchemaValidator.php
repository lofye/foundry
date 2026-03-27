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
        return $this->validateData($data, $schema);
    }

    /**
     * @param array<mixed> $data
     * @param array<string,mixed> $schema
     */
    public function validateData(array $data, array $schema): ValidationResult
    {
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

        if (array_key_exists('enum', $schema) && is_array($schema['enum'])) {
            if (!in_array($value, $schema['enum'], true)) {
                $errors[] = new ValidationError(
                    $path,
                    'Value not in enum set.',
                    expected: 'one of ' . implode(', ', array_map(
                        static fn (mixed $candidate): string => is_scalar($candidate) || $candidate === null
                            ? var_export($candidate, true)
                            : gettype($candidate),
                        $schema['enum'],
                    )),
                    actual: $this->describeValue($value),
                    suggestedFix: 'Replace the value at ' . $path . ' with one of the allowed enum values.',
                );
            }
        }

        if (is_int($value) || is_float($value)) {
            $this->validateNumberConstraints($value, $schema, $path, $errors);
        }

        if (is_array($value) && ($this->isAssoc($value) || ($value === [] && $this->expectsObject($schema)))) {
            $this->validateObject($value, $schema, $path, $errors);
            return;
        }

        if (is_array($value) && array_is_list($value) && $this->expectsArray($schema)) {
            $this->validateArray($value, $schema, $path, $errors);
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<int,ValidationError> $errors
     */
    private function validateNumberConstraints(int|float $value, array $schema, string $path, array &$errors): void
    {
        if (isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum'])) && $value < $schema['minimum']) {
            $errors[] = new ValidationError(
                $path,
                'Number smaller than minimum.',
                expected: '>=' . $schema['minimum'],
                actual: (string) $value,
                suggestedFix: 'Increase the numeric value at ' . $path . ' to meet the minimum.',
            );
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

        $expected = implode('|', array_map('strval', $types));
        $errors[] = new ValidationError(
            $path,
            'Type mismatch.',
            expected: $expected,
            actual: $this->describeValue($value),
            suggestedFix: 'Replace the value at ' . $path . ' with a ' . $expected . '.',
        );
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
                $errors[] = new ValidationError(
                    $path . '.' . (string) $name,
                    'Required property missing.',
                    expected: 'present property',
                    actual: 'missing',
                    suggestedFix: 'Add the required property ' . $path . '.' . (string) $name . '.',
                );
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
        }

        $allowAdditional = (bool) ($schema['additionalProperties'] ?? true);
        if (!$allowAdditional) {
            foreach ($value as $key => $_) {
                if (!array_key_exists($key, $properties)) {
                    $errors[] = new ValidationError(
                        $path . '.' . $key,
                        'Additional property is not allowed.',
                        expected: 'one of ' . implode(', ', array_keys($properties)),
                        actual: 'unexpected property',
                        suggestedFix: 'Remove ' . $path . '.' . $key . ' or rename it to a supported property.',
                    );
                }
            }
        }
    }

    /**
     * @param array<int,mixed> $value
     * @param array<string,mixed> $schema
     * @param array<int,ValidationError> $errors
     */
    private function validateArray(array $value, array $schema, string $path, array &$errors): void
    {
        if (isset($schema['minItems']) && is_int($schema['minItems']) && count($value) < $schema['minItems']) {
            $errors[] = new ValidationError(
                $path,
                'Array shorter than minItems.',
                expected: 'at least ' . $schema['minItems'] . ' item(s)',
                actual: count($value) . ' item(s)',
                suggestedFix: 'Add more items to ' . $path . '.',
            );
        }

        if (($schema['uniqueItems'] ?? false) === true) {
            $encoded = array_map(static fn (mixed $item): string => serialize($item), $value);
            if (count($encoded) !== count(array_unique($encoded))) {
                $errors[] = new ValidationError(
                    $path,
                    'Array items must be unique.',
                    expected: 'unique items',
                    actual: 'duplicate items',
                    suggestedFix: 'Remove duplicate entries from ' . $path . '.',
                );
            }
        }

        $itemSchema = is_array($schema['items'] ?? null) ? $schema['items'] : null;
        if ($itemSchema === null) {
            return;
        }

        foreach ($value as $index => $item) {
            $this->validateNode($item, $itemSchema, $path . '[' . $index . ']', $errors);
            if (is_string($item)) {
                $this->validateStringConstraints($item, $itemSchema, $path . '[' . $index . ']', $errors);
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
            $errors[] = new ValidationError(
                $path,
                'String shorter than minLength.',
                expected: 'length >= ' . $schema['minLength'],
                actual: 'length ' . mb_strlen($value),
                suggestedFix: 'Provide a longer string at ' . $path . '.',
            );
        }

        if (isset($schema['maxLength']) && is_int($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            $errors[] = new ValidationError(
                $path,
                'String longer than maxLength.',
                expected: 'length <= ' . $schema['maxLength'],
                actual: 'length ' . mb_strlen($value),
                suggestedFix: 'Shorten the string at ' . $path . '.',
            );
        }

        if (isset($schema['pattern']) && is_string($schema['pattern'])) {
            $regex = '/' . str_replace('/', '\\/', $schema['pattern']) . '/';
            if (@preg_match($regex, $value) !== 1) {
                $errors[] = new ValidationError(
                    $path,
                    'String does not match pattern.',
                    expected: 'pattern ' . $schema['pattern'],
                    actual: $this->describeValue($value),
                    suggestedFix: 'Update the value at ' . $path . ' so it matches the required pattern.',
                );
            }
        }

        if (($schema['format'] ?? null) === 'date-time' && strtotime($value) === false) {
            $errors[] = new ValidationError(
                $path,
                'String is not a valid date-time.',
                expected: 'RFC 3339 date-time string',
                actual: $this->describeValue($value),
                suggestedFix: 'Use an ISO-8601 date-time value at ' . $path . '.',
            );
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
     * @param array<string,mixed> $schema
     */
    private function expectsArray(array $schema): bool
    {
        $types = $schema['type'] ?? null;
        if ($types === null) {
            return false;
        }

        $types = is_array($types) ? $types : [$types];

        return in_array('array', array_map('strval', $types), true);
    }

    /**
     * @param array<mixed> $array
     */
    private function isAssoc(array $array): bool
    {
        return array_is_list($array) === false;
    }

    private function describeValue(mixed $value): string
    {
        return match (true) {
            is_array($value) && $this->isAssoc($value) => 'object',
            is_array($value) => 'array',
            is_string($value) => 'string(' . $value . ')',
            is_int($value) => 'integer(' . $value . ')',
            is_float($value) => 'number(' . $value . ')',
            is_bool($value) => 'boolean(' . ($value ? 'true' : 'false') . ')',
            $value === null => 'null',
            default => gettype($value),
        };
    }
}
