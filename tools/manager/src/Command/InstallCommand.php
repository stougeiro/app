<?php declare(strict_types=1);

namespace Sourcerer\Tool\Manager\Command;

use Sourcerer\Tool\Contract\CommandInterface;
use Sourcerer\Tool\Contract\ExitCode;
use Sourcerer\Tool\Contract\InputInterface;
use Sourcerer\Tool\Contract\OperatorFile;
use Sourcerer\Tool\Contract\Option;
use Sourcerer\Tool\Contract\OutputInterface;
use Sourcerer\Tool\Contract\ToolInterface;
use Sourcerer\Tool\Support\FileSystem;


final class InstallCommand implements CommandInterface
{
    /**
     * @param list<ToolInterface> $tools
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $tools,
    ) {
    }


    public function name(): string
    {
        return 'install';
    }

    public function description(): string
    {
        return 'Install the local .sourcerer control layout';
    }

    public function usage(): string
    {
        return 'sourcerer install';
    }

    /** @return list<Option> */
    public function options(): array
    {
        return [];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        OperatorFile::load($this->projectRoot);

        FileSystem::ensureDirectory(rtrim($this->projectRoot, '/') .'/.sourcerer');

        foreach ($this->tools as $tool) {
            foreach ($tool->install($this->projectRoot) as $line) {
                $output->line($line);
            }
        }

        $output->line();
        $output->line('next:');
        $output->line('  edit .sourcerer/code/config.json');
        $output->line('  edit .sourcerer/schema/config.json');
        $output->line('  run: ./sourcerer code --help');
        $output->line('  run: ./sourcerer schema --help');

        return ExitCode::Success->value;
    }
}
