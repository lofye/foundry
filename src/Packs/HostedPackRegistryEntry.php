<?php

declare(strict_types=1);

namespace Foundry\Packs;

use Foundry\Support\FoundryError;

final readonly class HostedPackRegistryEntry
{
    public function __construct(
        public string $name,
        public string $version,
        public string $description,
        public string $downloadUrl,
    ) {}

    /**
     * @return array<string,string>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'download_url' => $this->downloadUrl,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload, int $index = 0): self
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $version = trim((string) ($payload['version'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $downloadUrl = trim((string) ($payload['download_url'] ?? ''));

        $errors = [];

        if (!PackManifest::isValidName($name)) {
            $errors['name'] = 'name must match vendor/pack-name format.';
        }

        if (!PackManifest::isValidVersion($version)) {
            $errors['version'] = 'version must be a semantic version.';
        }

        if ($description === '') {
            $errors['description'] = 'description must be non-empty.';
        }

        if (!self::isValidDownloadUrl($downloadUrl)) {
            $errors['download_url'] = 'download_url must be an HTTPS URL.';
        }

        if ($errors !== []) {
            throw new FoundryError(
                'PACK_REGISTRY_ENTRY_INVALID',
                'validation',
                [
                    'index' => $index,
                    'errors' => $errors,
                    'entry' => $payload,
                ],
                'Hosted pack registry entry is invalid.',
            );
        }

        return new self(
            name: $name,
            version: $version,
            description: $description,
            downloadUrl: $downloadUrl,
        );
    }

    public static function isValidDownloadUrl(string $value): bool
    {
        $parts = parse_url($value);
        if (!is_array($parts)) {
            return false;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== '';
    }
}
