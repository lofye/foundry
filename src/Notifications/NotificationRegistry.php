<?php
declare(strict_types=1);

namespace Foundry\Notifications;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class NotificationRegistry
{
    /**
     * @var array<string,array<string,mixed>>|null
     */
    private ?array $notifications = null;

    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        if ($this->notifications !== null) {
            return $this->notifications;
        }

        $path = $this->indexPath();
        if (!is_file($path)) {
            $this->notifications = [];

            return $this->notifications;
        }

        /** @var mixed $raw */
        $raw = require $path;
        if (!is_array($raw)) {
            throw new FoundryError('NOTIFICATION_INDEX_INVALID', 'validation', ['path' => $path], 'Notification index must return an array.');
        }

        $rows = [];
        foreach ($raw as $name => $payload) {
            if (!is_string($name) || $name === '' || !is_array($payload)) {
                continue;
            }
            $rows[$name] = $payload;
        }
        ksort($rows);

        $this->notifications = $rows;

        return $this->notifications;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /**
     * @return array<string,mixed>
     */
    public function get(string $name): array
    {
        $row = $this->all()[$name] ?? null;
        if (!is_array($row)) {
            throw new FoundryError('NOTIFICATION_NOT_FOUND', 'not_found', ['notification' => $name], 'Notification not found.');
        }

        return $row;
    }

    private function indexPath(): string
    {
        $buildPath = $this->paths->join('app/.foundry/build/projections/notification_index.php');
        if (is_file($buildPath)) {
            return $buildPath;
        }

        return $this->paths->join('app/generated/notification_index.php');
    }
}
