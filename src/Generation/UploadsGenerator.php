<?php
declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class UploadsGenerator
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureGenerator $featureGenerator,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function generate(string $profile, bool $force = false): array
    {
        $profile = trim($profile);
        if (!in_array($profile, ['avatar', 'attachments'], true)) {
            throw new FoundryError('UPLOAD_PROFILE_INVALID', 'validation', ['profile' => $profile], 'Upload profile must be avatar or attachments.');
        }

        $featureDefinitions = $profile === 'avatar'
            ? $this->avatarDefinitions()
            : $this->attachmentDefinitions();

        $generatedFeatures = [];
        $generatedFiles = [];
        foreach ($featureDefinitions as $definition) {
            $generatedFeatures[] = (string) $definition['feature'];
            foreach ($this->featureGenerator->generateFromArray($definition, $force) as $path) {
                $generatedFiles[] = $path;
            }
        }

        foreach ($this->writeDefinition($profile, $generatedFeatures, $force) as $path) {
            $generatedFiles[] = $path;
        }

        foreach ($this->writeMigrations($force) as $path) {
            $generatedFiles[] = $path;
        }

        sort($generatedFeatures);
        sort($generatedFiles);

        return [
            'profile' => $profile,
            'features' => array_values(array_unique($generatedFeatures)),
            'files' => array_values(array_unique($generatedFiles)),
            'definition' => $this->paths->join('app/definitions/uploads/' . $profile . '.uploads.yaml'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function avatarDefinitions(): array
    {
        return [
            $this->uploadFeatureDefinition('upload_avatar', '/account/avatar/upload', 'upload', 'avatar'),
            $this->uploadFeatureDefinition('attach_avatar', '/account/avatar/attach', 'attach', 'avatar'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function attachmentDefinitions(): array
    {
        return [
            $this->uploadFeatureDefinition('upload_attachment', '/attachments/upload', 'upload', 'attachments'),
            $this->uploadFeatureDefinition('attach_attachment', '/attachments/attach', 'attach', 'attachments'),
            $this->uploadFeatureDefinition('delete_attachment', '/attachments/{id}/delete', 'delete', 'attachments'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function uploadFeatureDefinition(string $feature, string $path, string $operation, string $profile): array
    {
        $method = $operation === 'delete' ? 'DELETE' : 'POST';

        return [
            'feature' => $feature,
            'kind' => 'http',
            'description' => sprintf('Generated %s feature for %s uploads.', $operation, $profile),
            'route' => [
                'method' => $method,
                'path' => $path,
            ],
            'input' => [
                'fields' => [
                    'file' => ['type' => 'string', 'required' => $operation !== 'delete', 'form' => 'file'],
                    'owner_type' => ['type' => 'string', 'required' => false, 'form' => 'hidden'],
                    'owner_id' => ['type' => 'integer', 'required' => false, 'form' => 'hidden'],
                    'field_name' => ['type' => 'string', 'required' => false, 'form' => 'hidden'],
                ],
            ],
            'output' => [
                'fields' => [
                    'status' => ['type' => 'string', 'required' => true],
                    'file_id' => ['type' => 'integer', 'required' => false],
                ],
            ],
            'auth' => [
                'required' => true,
                'strategies' => ['session'],
                'permissions' => ['uploads.' . $profile . '.' . $operation],
            ],
            'csrf' => ['required' => true],
            'database' => [
                'reads' => ['files', 'file_attachments'],
                'writes' => ['files', 'file_attachments'],
                'transactions' => 'required',
                'queries' => [$feature],
            ],
            'cache' => [
                'reads' => [],
                'writes' => [],
                'invalidate' => ['uploads:' . $profile],
            ],
            'events' => [
                'emit' => ['upload.' . $profile . '.' . $operation],
                'subscribe' => [],
            ],
            'jobs' => [
                'dispatch' => $operation === 'upload' ? ['generate_' . $profile . '_variants'] : [],
            ],
            'rate_limit' => [
                'strategy' => 'user',
                'bucket' => 'uploads_' . $profile . '_' . $operation,
                'cost' => 1,
            ],
            'tests' => [
                'required' => ['contract', 'feature', 'auth', 'integration'],
            ],
            'uploads' => [
                'profile' => $profile,
                'operation' => $operation,
                'disk' => 'local',
                'allowed_mime_types' => $profile === 'avatar'
                    ? ['image/jpeg', 'image/png', 'image/webp']
                    : ['application/pdf', 'image/jpeg', 'image/png', 'text/plain'],
                'max_size_kb' => $profile === 'avatar' ? 2048 : 10240,
            ],
            'ui' => [
                'style' => 'server-rendered',
                'form' => [
                    'file_field' => true,
                ],
            ],
        ];
    }

    /**
     * @param array<int,string> $features
     * @return array<int,string>
     */
    private function writeDefinition(string $profile, array $features, bool $force): array
    {
        $dir = $this->paths->join('app/definitions/uploads');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . '/' . $profile . '.uploads.yaml';
        if (is_file($path) && !$force) {
            throw new FoundryError('UPLOAD_DEFINITION_EXISTS', 'io', ['path' => $path], 'Upload definition already exists. Use --force to overwrite.');
        }

        $document = [
            'version' => 1,
            'profile' => $profile,
            'disk' => 'local',
            'visibility' => 'private',
            'allowed_mime_types' => $profile === 'avatar'
                ? ['image/jpeg', 'image/png', 'image/webp']
                : ['application/pdf', 'image/jpeg', 'image/png', 'text/plain'],
            'max_size_kb' => $profile === 'avatar' ? 2048 : 10240,
            'ownership' => [
                'required' => true,
                'owner_type_field' => 'owner_type',
                'owner_id_field' => 'owner_id',
            ],
            'feature_names' => [
                'upload' => 'upload_' . ($profile === 'avatar' ? 'avatar' : 'attachment'),
                'attach' => 'attach_' . ($profile === 'avatar' ? 'avatar' : 'attachment'),
                'delete' => $profile === 'avatar' ? '' : 'delete_attachment',
            ],
            'features' => array_values(array_unique(array_map('strval', $features))),
        ];

        file_put_contents($path, Yaml::dump($document));

        return [$path];
    }

    /**
     * @return array<int,string>
     */
    private function writeMigrations(bool $force): array
    {
        $dir = $this->paths->join('database/migrations');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $migrations = [
            '0030_create_files.sql' => "CREATE TABLE IF NOT EXISTS files (\n    id INTEGER PRIMARY KEY AUTOINCREMENT,\n    disk TEXT NOT NULL,\n    path TEXT NOT NULL,\n    original_name TEXT NOT NULL,\n    mime_type TEXT NOT NULL,\n    size_bytes INTEGER NOT NULL,\n    checksum TEXT NULL,\n    created_at TEXT NOT NULL\n);\n",
            '0031_create_file_attachments.sql' => "CREATE TABLE IF NOT EXISTS file_attachments (\n    id INTEGER PRIMARY KEY AUTOINCREMENT,\n    file_id INTEGER NOT NULL,\n    owner_type TEXT NOT NULL,\n    owner_id INTEGER NOT NULL,\n    field_name TEXT NOT NULL,\n    created_at TEXT NOT NULL\n);\n",
        ];

        ksort($migrations);

        $written = [];
        foreach ($migrations as $file => $sql) {
            $path = $dir . '/' . $file;
            if (is_file($path) && !$force) {
                continue;
            }

            file_put_contents($path, $sql);
            $written[] = $path;
        }

        sort($written);

        return $written;
    }
}
