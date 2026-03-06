<?php
declare(strict_types=1);

namespace Forge\Verification;

use Forge\Support\Paths;
use Forge\Support\Yaml;

final class EventsVerifier
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function verify(): VerificationResult
    {
        $errors = [];
        $warnings = [];

        $emitted = [];
        $subscribesByFeature = [];

        foreach (glob($this->paths->features() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $feature = basename($dir);
            $eventsPath = $dir . '/events.yaml';
            if (!is_file($eventsPath)) {
                continue;
            }

            $events = Yaml::parseFile($eventsPath);
            foreach ((array) ($events['emit'] ?? []) as $emit) {
                if (!is_array($emit)) {
                    continue;
                }

                $name = (string) ($emit['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                if (!isset($emit['schema']) || !is_array($emit['schema'])) {
                    $errors[] = "{$feature}: emitted event {$name} missing schema";
                }

                $emitted[$name] = true;
            }

            $subscribesByFeature[$feature] = array_values(array_map('strval', (array) ($events['subscribe'] ?? [])));
            foreach ($subscribesByFeature[$feature] as $eventName) {
                if (!isset($emitted[$eventName])) {
                    $warnings[] = "{$feature}: subscribes to unknown event {$eventName}";
                }
            }
        }

        // coarse circular warning: feature subscribes to its own emitted event
        foreach (glob($this->paths->features() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $feature = basename($dir);
            $eventsPath = $dir . '/events.yaml';
            if (!is_file($eventsPath)) {
                continue;
            }

            $events = Yaml::parseFile($eventsPath);
            $emits = array_values(array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), array_filter((array) ($events['emit'] ?? []), 'is_array')));
            $subs = $subscribesByFeature[$feature] ?? [];
            foreach ($emits as $eventName) {
                if (in_array($eventName, $subs, true)) {
                    $warnings[] = "{$feature}: circular event chain for {$eventName}";
                }
            }
        }

        return new VerificationResult($errors === [], $errors, $warnings);
    }
}
