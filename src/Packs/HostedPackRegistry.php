<?php

declare(strict_types=1);

namespace Foundry\Packs;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class HostedPackRegistry
{
    private \Closure $fetcher;

    public function __construct(
        private readonly Paths $paths,
        ?callable $fetcher = null,
        private readonly ?string $registryUrlOverride = null,
    ) {
        $this->fetcher = $fetcher !== null
            ? \Closure::fromCallable($fetcher)
            : fn(string $url): string => $this->defaultFetch($url);
    }

    public function registryUrl(): string
    {
        $override = $this->registryUrlOverride ?? trim((string) (getenv('FOUNDRY_PACK_REGISTRY_URL') ?: ''));

        return $override !== ''
            ? $override
            : 'https://foundryframework.org/registry.json';
    }

    public function cachePath(): string
    {
        return $this->paths->join('.foundry/cache/registry.json');
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function search(string $query): array
    {
        $normalized = strtolower(trim($query));
        if ($normalized === '') {
            throw new FoundryError(
                'PACK_SEARCH_QUERY_REQUIRED',
                'validation',
                [],
                'A pack search query is required.',
            );
        }

        $matches = [];
        foreach ($this->entries() as $entry) {
            $haystack = strtolower($entry->name . ' ' . $entry->description);
            if (!str_contains($haystack, $normalized)) {
                continue;
            }

            $matches[] = $entry->toArray();
        }

        return $matches;
    }

    /**
     * @return array<string,string>
     */
    public function resolveLatest(string $name): array
    {
        $candidates = array_values(array_filter(
            $this->entries(),
            static fn(HostedPackRegistryEntry $entry): bool => $entry->name === $name,
        ));

        if ($candidates === []) {
            throw new FoundryError(
                'PACK_REGISTRY_PACK_NOT_FOUND',
                'not_found',
                [
                    'pack' => $name,
                    'registry_url' => $this->registryUrl(),
                ],
                'Pack was not found in the hosted registry.',
            );
        }

        usort(
            $candidates,
            static fn(HostedPackRegistryEntry $left, HostedPackRegistryEntry $right): int => version_compare($left->version, $right->version),
        );

        return $candidates[count($candidates) - 1]->toArray();
    }

    public function downloadArchive(string $url): string
    {
        if (!HostedPackRegistryEntry::isValidDownloadUrl($url)) {
            throw new FoundryError(
                'PACK_DOWNLOAD_URL_INVALID',
                'validation',
                ['download_url' => $url],
                'Hosted pack download URL must use HTTPS.',
            );
        }

        return $this->fetchBytes(
            $url,
            'PACK_DOWNLOAD_FAILED',
            'Pack archive download failed.',
            ['download_url' => $url],
        );
    }

    /**
     * @return array<int,HostedPackRegistryEntry>
     */
    public function entries(): array
    {
        $url = $this->registryUrl();
        if (!$this->isValidRegistryUrl($url)) {
            throw new FoundryError(
                'PACK_REGISTRY_URL_INVALID',
                'validation',
                ['registry_url' => $url],
                'Hosted pack registry URL must use HTTP or HTTPS.',
            );
        }

        $payload = $this->fetchBytes(
            $url,
            'PACK_REGISTRY_UNAVAILABLE',
            'Hosted pack registry is unavailable.',
            ['registry_url' => $url],
        );

        $entries = $this->decodeEntries($payload, $url);
        $this->writeCache($entries);

        return $entries;
    }

    /**
     * @return array<int,HostedPackRegistryEntry>
     */
    private function decodeEntries(string $payload, string $url): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new FoundryError(
                'PACK_REGISTRY_INVALID_JSON',
                'parsing',
                ['registry_url' => $url],
                'Hosted pack registry must return valid JSON.',
                0,
                $error,
            );
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new FoundryError(
                'PACK_REGISTRY_INVALID',
                'validation',
                ['registry_url' => $url],
                'Hosted pack registry root must be a JSON array.',
            );
        }

        $entries = [];
        $seen = [];

        foreach ($decoded as $index => $row) {
            if (!is_array($row)) {
                throw new FoundryError(
                    'PACK_REGISTRY_ENTRY_INVALID',
                    'validation',
                    [
                        'index' => $index,
                        'entry' => $row,
                    ],
                    'Hosted pack registry entries must be JSON objects.',
                );
            }

            $entry = HostedPackRegistryEntry::fromArray($row, $index);
            $key = $entry->name . '@' . $entry->version;

            if (isset($seen[$key])) {
                throw new FoundryError(
                    'PACK_REGISTRY_DUPLICATE_ENTRY',
                    'validation',
                    [
                        'registry_url' => $url,
                        'pack' => $entry->name,
                        'version' => $entry->version,
                    ],
                    'Hosted pack registry contains duplicate name/version entries.',
                );
            }

            $seen[$key] = true;
            $entries[] = $entry;
        }

        usort(
            $entries,
            static fn(HostedPackRegistryEntry $left, HostedPackRegistryEntry $right): int => strcmp($left->name, $right->name)
                ?: version_compare($left->version, $right->version),
        );

        return $entries;
    }

    /**
     * @param array<string,mixed> $details
     */
    private function fetchBytes(string $url, string $errorCode, string $message, array $details): string
    {
        try {
            $payload = ($this->fetcher)($url);
        } catch (FoundryError $error) {
            throw new FoundryError(
                $errorCode,
                'network',
                $details + ['source_error' => $error->errorCode],
                $message,
                0,
                $error,
            );
        } catch (\Throwable $error) {
            throw new FoundryError(
                $errorCode,
                'network',
                $details + ['exception' => $error::class],
                $message,
                0,
                $error,
            );
        }

        if (!is_string($payload)) {
            throw new FoundryError(
                $errorCode,
                'network',
                $details,
                $message,
            );
        }

        return $payload;
    }

    private function defaultFetch(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
                'user_agent' => 'Foundry Pack Registry Client/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $lastError = null;
        set_error_handler(static function (int $severity, string $message) use (&$lastError): bool {
            $lastError = $message;

            return true;
        });

        try {
            $payload = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        if ($payload === false) {
            throw new \RuntimeException($lastError ?? 'Unable to fetch URL.');
        }

        /** @var array<int,string>|null $headers */
        $headers = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : null;

        if (isset($headers[0]) && preg_match('/\s(\d{3})(?:\s|$)/', (string) $headers[0], $matches) === 1) {
            $status = (int) ($matches[1] ?? 0);
            if ($status >= 400) {
                throw new \RuntimeException('HTTP request failed with status ' . $status . '.');
            }
        }

        return $payload;
    }

    /**
     * @param array<int,HostedPackRegistryEntry> $entries
     */
    private function writeCache(array $entries): void
    {
        $directory = dirname($this->cachePath());
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            return;
        }

        $rows = array_values(array_map(
            static fn(HostedPackRegistryEntry $entry): array => $entry->toArray(),
            $entries,
        ));

        @file_put_contents($this->cachePath(), Json::encode($rows, true));
    }

    private function isValidRegistryUrl(string $value): bool
    {
        $parts = parse_url($value);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return in_array($scheme, ['http', 'https'], true)
            && trim((string) ($parts['host'] ?? '')) !== '';
    }
}
