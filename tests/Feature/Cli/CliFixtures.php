<?php declare(strict_types=1);

namespace Tests\Feature\Cli;

use Sourcerer\Tool\Manager\Application;
use Sourcerer\Tool\Contract\CommandInterface;
use Sourcerer\Tool\Contract\InputInterface;
use Sourcerer\Tool\Contract\Option;
use Sourcerer\Tool\Contract\OutputInterface;


final class RecordingCommand implements CommandInterface
{
    /** @param list<Option> $options */
    public function __construct(
        protected string $commandName,
        protected array $options = [],
        protected int $exitCode = 0,
    ) {
    }


    public function name(): string
    {
        return $this->commandName;
    }

    public function description(): string
    {
        return 'recording command';
    }

    public function usage(): string
    {
        return 'sourcerer '. $this->commandName;
    }

    /** @return list<Option> */
    public function options(): array
    {
        return $this->options;
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $output->line('command='. $this->commandName);
        $output->line('name='. ($input->string('name') ?? ''));
        $output->line('flag='. ($input->bool('flag') ? '1' : '0'));
        $output->line('args='. implode(',', $input->arguments()));

        return $this->exitCode;
    }
}


final class CliFixtures
{
    /**
     * @param array<string, CommandInterface> $commands
     * @param list<string> $argv
     * @return array{code: int, stdout: string, stderr: string}
     */
    public static function run(array $commands, array $argv): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $stdin = fopen('php://memory', 'r');

        if ($stdout === false || $stderr === false || $stdin === false) {
            throw new \RuntimeException('could not open test streams');
        }

        $code = (new Application($commands, '1.2.3', $stdout, $stderr, $stdin))->run($argv);

        rewind($stdout);
        rewind($stderr);

        return [
            'code' => $code,
            'stdout' => (string) stream_get_contents($stdout),
            'stderr' => (string) stream_get_contents($stderr),
        ];
    }
}
