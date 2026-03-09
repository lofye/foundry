<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Support\FoundryError;

final class GeneratePhaseOneCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        if (($args[0] ?? null) !== 'generate') {
            return false;
        }

        $target = (string) ($args[1] ?? '');

        return in_array($target, ['starter', 'resource', 'admin-resource', 'uploads'], true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $target = (string) ($args[1] ?? '');
        $force = in_array('--force', $args, true);

        return match ($target) {
            'starter' => $this->generateStarter($args, $context, $force),
            'resource' => $this->generateResource($args, $context, $force),
            'admin-resource' => $this->generateAdminResource($args, $context, $force),
            'uploads' => $this->generateUploads($args, $context, $force),
            default => throw new FoundryError('CLI_GENERATE_TARGET_INVALID', 'validation', ['target' => $target], 'Unsupported generation target.'),
        };
    }

    /**
     * @param array<int,string> $args
     */
    private function generateStarter(array $args, CommandContext $context, bool $force): array
    {
        $starter = (string) ($args[2] ?? '');
        if ($starter === '') {
            throw new FoundryError('CLI_STARTER_REQUIRED', 'validation', [], 'Starter name required (server-rendered or api).');
        }

        $name = $this->extractOption($args, '--name');
        $result = $context->starterGenerator()->generate($starter, $force, $name);

        return [
            'status' => 0,
            'message' => 'Starter generated.',
            'payload' => $result,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function generateResource(array $args, CommandContext $context, bool $force): array
    {
        $resource = (string) ($args[2] ?? '');
        if ($resource === '') {
            throw new FoundryError('CLI_RESOURCE_REQUIRED', 'validation', [], 'Resource name required.');
        }

        $spec = $this->extractOption($args, '--spec');
        if ($spec === null || $spec === '') {
            throw new FoundryError('CLI_RESOURCE_SPEC_REQUIRED', 'validation', [], 'Resource spec path required (--spec=<file>).');
        }

        $result = $context->resourceGenerator()->generate($resource, $spec, $force);

        return [
            'status' => 0,
            'message' => 'Resource generated.',
            'payload' => $result,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function generateAdminResource(array $args, CommandContext $context, bool $force): array
    {
        $resource = (string) ($args[2] ?? '');
        if ($resource === '') {
            throw new FoundryError('CLI_ADMIN_RESOURCE_REQUIRED', 'validation', [], 'Admin resource name required.');
        }

        $spec = $this->extractOption($args, '--spec');
        $result = $context->adminResourceGenerator()->generate($resource, $spec, $force);

        return [
            'status' => 0,
            'message' => 'Admin resource generated.',
            'payload' => $result,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function generateUploads(array $args, CommandContext $context, bool $force): array
    {
        $profile = (string) ($args[2] ?? '');
        if ($profile === '') {
            throw new FoundryError('CLI_UPLOAD_PROFILE_REQUIRED', 'validation', [], 'Upload profile required (avatar or attachments).');
        }

        $result = $context->uploadsGenerator()->generate($profile, $force);

        return [
            'status' => 0,
            'message' => 'Uploads generated.',
            'payload' => $result,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function extractOption(array $args, string $name): ?string
    {
        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, $name . '=')) {
                $value = substr($arg, strlen($name . '='));

                return $value !== '' ? $value : null;
            }

            if ($arg === $name) {
                $value = (string) ($args[$index + 1] ?? '');

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }
}
