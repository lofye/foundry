<?php

declare(strict_types=1);

namespace Foundry\UX;

use Foundry\CLI\Application;
use Foundry\CLI\CommandContext;
use Foundry\Examples\ExampleLoader;
use Foundry\Support\CliCommandPrefix;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class FirstRunService
{
    public function __construct(
        private readonly ?ExampleLoader $exampleLoader = null,
        private readonly ?bool $interactive = null,
        private readonly ?\Closure $inputReader = null,
    ) {}

    /**
     * @param array<int,string> $args
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    public function run(CommandContext $context, array $args = []): array
    {
        $loader = $this->exampleLoader ?? new ExampleLoader($context->paths());
        $example = $this->option($args, '--example');
        if ($example !== null && $example !== '') {
            return $this->loadExampleFlow($context, $example, in_array('--temp', $args, true), 'init');
        }

        if ($this->projectDetected($context->paths())) {
            return $this->existingProjectFlow($context, 'default');
        }

        if ($context->expectsJson()) {
            $recommended = $loader->recommended();

            return $this->loadExampleFlow(
                $context,
                (string) ($recommended['name'] ?? 'blog-api'),
                !$this->isWorkingDirectoryEmpty($context->paths()->root()),
                'default',
            );
        }

        $selection = $this->interactiveSelection($loader);

        return match ($selection) {
            '2' => $this->inspectCurrentDirectoryFlow($context),
            '3' => $this->exitFlow($context),
            default => $this->loadExampleFlow(
                $context,
                $this->interactiveExampleSelection($loader),
                !$this->isWorkingDirectoryEmpty($context->paths()->root()),
                'default',
            ),
        };
    }

    /**
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    public function loadExampleFlow(
        CommandContext $context,
        string $example,
        bool $preferTemp = false,
        string $entrypoint = 'examples:load',
    ): array {
        $loader = $this->exampleLoader ?? new ExampleLoader($context->paths());
        $loaded = $loader->load($example, $context->paths()->root(), $preferTemp);
        $targetPath = (string) ($loaded['target_path'] ?? $context->paths()->root());

        $graph = $this->runCli($targetPath, ['foundry', 'inspect', 'graph'], $context->expectsJson());
        $explain = $this->runCli($targetPath, ['foundry', 'explain'], $context->expectsJson());

        $payload = [
            'mode' => 'example',
            'entrypoint' => $entrypoint,
            'project_detected' => false,
            'example' => $loaded['example'] ?? null,
            'target_path' => $targetPath,
            'workspace_mode' => (string) ($loaded['workspace_mode'] ?? 'working_directory'),
            'files_copied' => array_values(array_map('strval', (array) ($loaded['files_copied'] ?? []))),
            'graph' => $graph['payload'],
            'explain' => $explain['payload'],
            'next_steps' => $this->nextSteps(
                $targetPath,
                is_array($loaded['example'] ?? null) ? (array) $loaded['example'] : [],
            ),
        ];

        return [
            'status' => 0,
            'payload' => $context->expectsJson() ? $payload : null,
            'message' => $context->expectsJson() ? null : $this->renderExampleMessage($payload, $graph['output'], $explain['output']),
        ];
    }

    /**
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    private function existingProjectFlow(CommandContext $context, string $entrypoint): array
    {
        $graph = $this->runCli($context->paths()->root(), ['foundry', 'inspect', 'graph'], $context->expectsJson());
        $explain = $this->runCli($context->paths()->root(), ['foundry', 'explain'], $context->expectsJson());

        $payload = [
            'mode' => 'existing_project',
            'entrypoint' => $entrypoint,
            'project_detected' => true,
            'project_root' => $context->paths()->root(),
            'graph' => $graph['payload'],
            'explain' => $explain['payload'],
            'next_steps' => [
                'Modify the app: ' . CliCommandPrefix::foundry($context->paths()) . ' generate "Add a feature" --mode=new',
                'Inspect architecture: ' . CliCommandPrefix::foundry($context->paths()) . ' explain --json',
                'Run diagnostics: ' . CliCommandPrefix::foundry($context->paths()) . ' doctor',
            ],
        ];

        return [
            'status' => 0,
            'payload' => $context->expectsJson() ? $payload : null,
            'message' => $context->expectsJson() ? null : $this->renderExistingProjectMessage($payload, $graph['output'], $explain['output']),
        ];
    }

    /**
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    private function inspectCurrentDirectoryFlow(CommandContext $context): array
    {
        if ($this->projectDetected($context->paths())) {
            return $this->existingProjectFlow($context, 'default');
        }

        $payload = [
            'mode' => 'no_project',
            'entrypoint' => 'default',
            'project_detected' => false,
            'next_steps' => [
                'Load the recommended canonical example: ' . CliCommandPrefix::foundry($context->paths()) . ' init --example=blog-api',
                'Scaffold a new app: ' . CliCommandPrefix::foundry($context->paths()) . ' new demo-app --starter=standard',
                'Browse onboarding examples: ' . CliCommandPrefix::foundry($context->paths()) . ' examples:list --json',
            ],
        ];

        return [
            'status' => 0,
            'payload' => $context->expectsJson() ? $payload : null,
            'message' => $context->expectsJson() ? null : $this->renderNoProjectMessage($payload),
        ];
    }

    /**
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    private function exitFlow(CommandContext $context): array
    {
        $payload = [
            'mode' => 'exit',
            'entrypoint' => 'default',
            'project_detected' => false,
        ];

        return [
            'status' => 0,
            'payload' => $context->expectsJson() ? $payload : null,
            'message' => $context->expectsJson() ? null : 'First-run setup skipped.',
        ];
    }

    private function projectDetected(Paths $paths): bool
    {
        if ((glob($paths->join('app/features/*/feature.yaml')) ?: []) !== []) {
            return true;
        }

        if ((glob($paths->join('app/definitions/*')) ?: []) !== []) {
            return true;
        }

        return is_file($paths->join('app/.foundry/build/graph/app_graph.json'))
            || is_file($paths->join('.foundry/packs/installed.json'))
            || is_file($paths->join('foundry.extensions.php'))
            || is_file($paths->join('config/foundry/extensions.php'));
    }

    private function isWorkingDirectoryEmpty(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }

        $items = scandir($path);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            return false;
        }

        return true;
    }

    private function interactiveSelection(ExampleLoader $loader): string
    {
        if (!$this->isInteractive()) {
            return '1';
        }

        $this->writeLine('Foundry Framework');
        $this->writeLine('');
        $this->writeLine('Build and evolve applications using a structured architecture graph.');
        $this->writeLine('');
        $this->writeLine("Let's get you to your first result.");
        $this->writeLine('');
        $this->writeLine('Choose an option:');
        $this->writeLine('');
        $recommended = $loader->recommended();
        $this->writeLine('1) Load an onboarding example and inspect architecture (recommended)');
        $this->writeLine('   recommended: ' . (string) ($recommended['menu_label'] ?? $recommended['label'] ?? ''));
        $this->writeLine('2) Inspect current project');
        $this->writeLine('3) Exit');
        $this->writeLine('');

        $selection = $this->readLine('> ');

        return in_array($selection, ['1', '2', '3'], true) ? $selection : '1';
    }

    private function interactiveExampleSelection(ExampleLoader $loader): string
    {
        $examples = $loader->available();
        $recommended = $loader->recommended();

        if (!$this->isInteractive()) {
            return (string) ($recommended['name'] ?? 'blog-api');
        }

        $this->writeLine('Select an example:');
        $this->writeLine('');

        foreach ($examples as $index => $example) {
            if (!is_array($example)) {
                continue;
            }

            $this->writeLine(($index + 1) . ') ' . (string) ($example['menu_label'] ?? $example['label'] ?? 'Example'));
            $this->writeLine(
                '   taxonomy: ' . (string) ($example['taxonomy'] ?? 'reference')
                . '; load: ' . (string) ($example['mode'] ?? 'direct_copy')
                . '; sources: ' . implode(', ', array_values(array_map('strval', (array) ($example['source_examples'] ?? [])))),
            );
        }

        $this->writeLine('');

        $selection = trim($this->readLine('> '));
        if (ctype_digit($selection)) {
            $offset = (int) $selection - 1;
            if (isset($examples[$offset]) && is_array($examples[$offset])) {
                return (string) ($examples[$offset]['name'] ?? $recommended['name'] ?? 'blog-api');
            }
        }

        return (string) ($recommended['name'] ?? 'blog-api');
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,output:string,payload:array<string,mixed>|null}
     */
    private function runCli(string $cwd, array $argv, bool $json): array
    {
        $app = new Application();
        $previousCwd = getcwd() ?: '.';
        $command = $argv;
        if ($json) {
            $command[] = '--json';
        }

        ob_start();

        try {
            chdir($cwd);
            $status = $app->run($command);
        } finally {
            $output = trim((string) ob_get_clean());
            chdir($previousCwd);
        }

        if ($status !== 0) {
            throw new FoundryError(
                'FIRST_RUN_SUBCOMMAND_FAILED',
                'runtime',
                ['command' => $command, 'cwd' => $cwd, 'output' => $output, 'status' => $status],
                'Foundry could not complete the first-run walkthrough.',
            );
        }

        return [
            'status' => $status,
            'output' => $output,
            'payload' => $json && $output !== ''
                ? json_decode($output, true, 512, JSON_THROW_ON_ERROR)
                : null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderExampleMessage(array $payload, string $graphOutput, string $explainOutput): string
    {
        $example = is_array($payload['example'] ?? null) ? $payload['example'] : [];
        $lines = [
            'Foundry Framework',
            '',
            'Build and evolve applications using a structured architecture graph.',
            '',
            "Let's get you to your first result.",
            '',
            'Loaded example: ' . (string) ($example['label'] ?? 'Example'),
            'Taxonomy: ' . (string) ($example['taxonomy'] ?? 'reference'),
            'Load mode: ' . (string) ($example['mode'] ?? 'direct_copy'),
            'Sources: ' . implode(', ', array_values(array_map('strval', (array) ($example['source_examples'] ?? [])))),
            'Location: ' . (string) ($payload['target_path'] ?? ''),
            '',
            $graphOutput,
            '',
            $explainOutput,
            '',
            'Next steps:',
            '',
        ];

        foreach ((array) ($payload['next_steps'] ?? []) as $step) {
            $lines[] = '- ' . (string) $step;
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderExistingProjectMessage(array $payload, string $graphOutput, string $explainOutput): string
    {
        $lines = [
            $graphOutput,
            '',
            $explainOutput,
            '',
            'This project is ready.',
            '',
            'Try:',
        ];

        foreach ((array) ($payload['next_steps'] ?? []) as $step) {
            $step = trim((string) $step);
            if ($step === '') {
                continue;
            }

            $parts = explode(': ', $step, 2);
            $lines[] = '  ' . ($parts[1] ?? $parts[0]);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderNoProjectMessage(array $payload): string
    {
        $lines = [
            'Foundry Framework',
            '',
            'No Foundry project is active in this directory yet.',
            'Use `foundry init --example=blog-api` to load the recommended canonical example.',
            '',
            'Next steps:',
            '',
        ];

        foreach ((array) ($payload['next_steps'] ?? []) as $step) {
            $lines[] = '- ' . (string) $step;
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $example
     * @return array<int,string>
     */
    private function nextSteps(string $targetPath, array $example): array
    {
        $paths = new Paths($targetPath, Paths::fromCwd()->frameworkRoot());
        $prefix = CliCommandPrefix::foundry($paths);
        $intent = trim((string) ($example['next_generate_intent'] ?? 'Add a feature'));
        $mode = trim((string) ($example['next_generate_mode'] ?? 'new'));
        $target = $example['next_generate_target'] !== null
            ? trim((string) $example['next_generate_target'])
            : '';

        $generate = $prefix . ' generate "' . $intent . '" --mode=' . $mode;
        if ($target !== '') {
            $generate .= ' --target=' . $target;
        }

        return [
            'Modify the app: ' . $generate,
            'Inspect architecture: ' . $prefix . ' explain --json',
            'Run diagnostics: ' . $prefix . ' doctor',
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function option(array $args, string $name): ?string
    {
        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, $name . '=')) {
                return trim(substr($arg, strlen($name . '=')));
            }

            if ($arg === $name) {
                return trim((string) ($args[$index + 1] ?? ''));
            }
        }

        return null;
    }

    private function isInteractive(): bool
    {
        if ($this->interactive !== null) {
            return $this->interactive;
        }

        if (!defined('STDIN')) {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN);
        }

        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDIN);
        }

        return false;
    }

    private function readLine(string $prompt): string
    {
        if ($this->inputReader instanceof \Closure) {
            $line = ($this->inputReader)($prompt);

            return is_string($line) ? trim($line) : '';
        }

        if (function_exists('readline')) {
            $line = readline($prompt);

            return is_string($line) ? trim($line) : '';
        }

        $this->write($prompt);
        $handle = fopen('php://stdin', 'r');
        if (!is_resource($handle)) {
            return '';
        }

        $line = fgets($handle);

        return is_string($line) ? trim($line) : '';
    }

    private function writeLine(string $line): void
    {
        echo $line . PHP_EOL;
    }

    private function write(string $text): void
    {
        echo $text;
    }
}
