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


    /**
     * @param array<string, CommandInterface> $commands
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
    ) {
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->stdin = $stdin;
    }


    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $tokens = $argv;

        if ($tokens === [] || $tokens[0] === 'help' || $tokens[0] === '--help' || $tokens[0] === '-h') {
            $output = $this->output(new Input());
            HelpRenderer::global($this->commands, self::globalOptions(), $output, $this->version);

            return ExitCode::Success->value;
        }

        if ($tokens[0] === '--version') {
            $this->output(new Input())->line("sourcerer {$this->version}");

            return ExitCode::Success->value;
        }

        [$command, $consumed] = $this->resolve($tokens);

        if ($command === null) {
            $output = $this->output(new Input());
            $group = $this->group($tokens);

            if ($group !== null) {
                HelpRenderer::group($group, $this->commands, self::globalOptions(), $output);

                return ExitCode::Success->value;
            }

            $output->error("unknown command: {$tokens[0]}");
            HelpRenderer::global($this->commands, self::globalOptions(), $output, $this->version);

            return ExitCode::Invalid->value;
        }

        try {
            $input = Input::parse(
                array_slice($tokens, $consumed),
                [...$command->options(), ...self::globalOptions()],
            );
        } catch (InvalidArgumentException $exception) {
            $this->output(new Input())->error($exception->getMessage());

            return ExitCode::Invalid->value;
        }

        $output = $this->output($input);

        if ($input->bool('help')) {
            HelpRenderer::command($command, self::globalOptions(), $output);

            return ExitCode::Success->value;
        }

        if ($input->bool('version')) {
            $output->line("sourcerer {$this->version}");

            return ExitCode::Success->value;
        }

        try {
            return $command->run($input, $output);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Failure->value;
        }
    }


    /**
     * Returns a command group when the tokens name a prefix and request help
     * or no subcommand.
     *
     * @param list<string> $tokens
     */
    protected function group(array $tokens): ?string
    {
        $group = $tokens[0] ?? '';

        if ($group === '') {
            return null;
        }

        if (isset($tokens[1]) && ! in_array($tokens[1], ['--help', '-h'], true)) {
            return null;
        }

        foreach ($this->commands as $name => $command) {
            if (str_starts_with($name, $group .' ')) {
                return $group;
            }
        }

        return null;
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

    protected function output(Input $input): Output
    {
        return new Output(
            $this->stdout,
            $this->stderr,
            $input->bool('quiet'),
            ! $input->bool('no-ansi'),
        );
    }

    /** @return list<Option> */
    public static function globalOptions(): array
    {
        return [
            new Option('help', 'Show this help'),
            new Option('version', 'Show the version'),
            new Option('json', 'Write structured output'),
            new Option('quiet', 'Suppress output'),
            new Option('no-ansi', 'Disable colors'),
            new Option('no-interaction', 'Do not ask questions'),
        ];
    }
}
