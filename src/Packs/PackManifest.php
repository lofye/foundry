<?php

declare(strict_types=1);

namespace Foundry\Packs;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;

final readonly class PackManifest
{
    /**
     * @param array<int,string> $capabilities
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $description,
        public string $entry,
        public array $capabilities = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'entry' => $this->entry,
            'capabilities' => $this->capabilities,
        ];
    }

    public function vendor(): string
    {
        return explode('/', $this->name, 2)[0];
    }

    public function package(): string
    {
        return explode('/', $this->name, 2)[1] ?? '';
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new FoundryError(
                'PACK_MANIFEST_MISSING',
                'not_found',
                ['path' => $path],
                'Pack manifest not found.',
            );
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new FoundryError(
                'PACK_MANIFEST_UNREADABLE',
                'io',
                ['path' => $path],
                'Pack manifest could not be read.',
            );
        }

        try {
            $payload = Json::decodeAssoc($content);
        } catch (FoundryError $error) {
            throw new FoundryError(
                'PACK_MANIFEST_INVALID_JSON',
                'parsing',
                ['path' => $path, 'error' => $error->errorCode],
                'Pack manifest must be valid JSON.',
                0,
                $error,
            );
        }

        return self::fromArray($payload, $path);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload, string $sourcePath = 'foundry.json'): self
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $version = trim((string) ($payload['version'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $entry = ltrim(trim((string) ($payload['entry'] ?? '')), '\\');
        $capabilities = $payload['capabilities'] ?? null;

        $errors = [];

        if (!self::isValidName($name)) {
            $errors['name'] = 'name must match vendor/pack-name format.';
        }

        if (!self::isValidVersion($version)) {
            $errors['version'] = 'version must be a semantic version.';
        }

        if ($description === '') {
            $errors['description'] = 'description must be non-empty.';
        }

        if (!self::isValidClassName($entry)) {
            $errors['entry'] = 'entry must be a valid PHP class name.';
        }

        if (!is_array($capabilities)) {
            $errors['capabilities'] = 'capabilities must be an array of strings.';
            $capabilityList = [];
        } else {
            $capabilityList = [];
            foreach ($capabilities as $index => $capability) {
                if (!is_string($capability) || trim($capability) === '') {
                    $errors['capabilities.' . $index] = 'capabilities must contain only non-empty strings.';
                    continue;
                }

                $capabilityList[] = trim($capability);
            }
        }

        if ($errors !== []) {
            throw new FoundryError(
                'PACK_MANIFEST_INVALID',
                'validation',
                [
                    'path' => $sourcePath,
                    'errors' => $errors,
                ],
                'Pack manifest is invalid.',
            );
        }

        $capabilityList = array_values(array_unique($capabilityList));
        sort($capabilityList);

        return new self(
            name: $name,
            version: $version,
            description: $description,
            entry: $entry,
            capabilities: $capabilityList,
        );
    }

    public static function isValidName(string $value): bool
    {
        return preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*\/[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $value) === 1;
    }

    public static function isValidVersion(string $value): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $value) === 1;
    }

    public static function isValidClassName(string $value): bool
    {
        return preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
    }
}
