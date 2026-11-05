<?php declare(strict_types=1);

namespace Tool\Cli;

use InvalidArgumentException;
use Throwable;
use Tool\Contract\CommandInterface;
use Tool\Contract\ExitCode;
use Tool\Contract\Option;


final class Application
{
    /** @var resource|null */
    protected $stdout;

    /** @var resource|null */
    protected $stderr;

    /** @var resource|null */
    protected $stdin;

    /** @var array<string, array{description: string, version: string}> */
    protected array $groups;


    /**
     * @param array<string, CommandInterface> $commands
     * @param array<string, array{description: string, version: string}> $groups
     * @param resource|null $stdout
     * @param resource|null $stderr
     * @param resource|null $stdin
     */
    public function __construct(
        protected array $commands,
        protected string $version = '0.1.0',
        $stdout = null,
        $stderr = null,
        $stdin = null,
        array $groups = [],
    ) {
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->stdin = $stdin;
        $this->groups = $groups !== [] ? $groups : $this->deriveGroups();
    }


    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $tokens = $argv;

        if ($tokens === []) {
            HelpRenderer::global($this->commands, self::globalOptions(), $this->output(), $this->version, $this->groups);

            return ExitCode::Success->value;
        }

        if ($tokens[0] === '--help' || $tokens[0] === '-h') {
            return $this->imperativeHelp($tokens, null);
        }

        if ($tokens[0] === '--version') {
            return $this->imperativeVersion($tokens, null);
        }

        if (isset($this->groups[$tokens[0]]) && in_array($tokens[1] ?? null, ['--help', '--version'], true)) {
            if ($tokens[1] === '--help') {
                return $this->imperativeHelp($tokens, $tokens[0]);
            }

            return $this->imperativeVersion($tokens, $tokens[0]);
        }

        [$command, $consumed] = $this->resolve($tokens);

        if ($command === null) {
            $output = $this->output();

            if (isset($this->groups[$tokens[0]]) && count($tokens) === 1) {
                $this->renderToolHelp($tokens[0], $output);

                return ExitCode::Success->value;
            }

            $output->error('unknown command: '. implode(' ', $tokens));
            HelpRenderer::global($this->commands, self::globalOptions(), $output, $this->version, $this->groups);

            return ExitCode::Invalid->value;
        }

        try {
            $input = Input::parse(array_slice($tokens, $consumed), $command->options());
        } catch (InvalidArgumentException $exception) {
            $this->output()->error($exception->getMessage());

            return ExitCode::Invalid->value;
        }

        try {
            return $command->run($input, $this->output());
        } catch (Throwable $exception) {
            $this->output()->error($exception->getMessage());

            return ExitCode::Failure->value;
        }
    }


    /** @param list<string> $tokens */
    protected function imperativeHelp(array $tokens, ?string $group): int
    {
        $maximum = $group === null ? 1 : 2;

        if (count($tokens) !== $maximum) {
            $this->output()->error('--help must be used alone');

            return ExitCode::Invalid->value;
        }

        $output = $this->output();

        if ($group === null) {
            HelpRenderer::global($this->commands, self::globalOptions(), $output, $this->version, $this->groups);
        } else {
            $this->renderToolHelp($group, $output);
        }

        return ExitCode::Success->value;
    }


    /** @param list<string> $tokens */
    protected function imperativeVersion(array $tokens, ?string $group): int
    {
        $maximum = $group === null ? 1 : 2;

        if (count($tokens) !== $maximum) {
            $this->output()->error('--version must be used alone');

            return ExitCode::Invalid->value;
        }

        if ($group === null) {
            $this->output()->line("sourcerer {$this->version}");
        } else {
            $this->output()->line($group .' '. $this->groups[$group]['version']);
        }

        return ExitCode::Success->value;
    }


    protected function renderToolHelp(string $group, Output $output): void
    {
        HelpRenderer::group(
            $group,
            $this->commands,
            self::globalOptions(),
            $output,
            $this->groups[$group]['description'],
            $this->groups[$group]['version'],
        );
    }


    /**
     * @param list<string> $tokens
     * @return array{0: ?CommandInterface, 1: int}
     */
    protected function resolve(array $tokens): array
    {
        $matched = null;
        $consumed = 0;

        foreach ($this->commands as $name => $command) {
            $parts = explode(' ', $name);

            if (count($parts) <= $consumed) {
                continue;
            }

            if (array_slice($tokens, 0, count($parts)) !== $parts) {
                continue;
            }

            $matched = $command;
            $consumed = count($parts);
        }

        return [$matched, $consumed];
    }


    /** @return array<string, array{description: string, version: string}> */
    protected function deriveGroups(): array
    {
        $groups = [];

        foreach ($this->commands as $name => $command) {
            $group = explode(' ', $name)[0];
            $groups[$group] ??= [
                'description' => $command->description(),
                'version' => $this->version,
            ];
        }

        return $groups;
    }


    protected function output(): Output
    {
        return new Output($this->stdout, $this->stderr);
    }

    /** @return list<Option> */
    public static function globalOptions(): array
    {
        return [
            new Option('help', 'Show this help'),
            new Option('version', 'Show the version'),
        ];
    }
}
