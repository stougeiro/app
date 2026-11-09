<?php declare(strict_types=1);

namespace Sourcerer\Tool\Manager\Command;

use Sourcerer\Tool\Contract\CommandInterface;
use Sourcerer\Tool\Contract\InputInterface;
use Sourcerer\Tool\Contract\OperatorFile;
use Sourcerer\Tool\Contract\Option;
use Sourcerer\Tool\Contract\OutputInterface;


final class OperatorRequiredCommand implements CommandInterface
{
    public function __construct(
        private readonly CommandInterface $command,
        private readonly string $projectRoot,
    ) {
    }


    public function name(): string
    {
        return $this->command->name();
    }

    public function description(): string
    {
        return $this->command->description();
    }

    public function usage(): string
    {
        return $this->command->usage();
    }

    /** @return list<Option> */
    public function options(): array
    {
        return $this->command->options();
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        OperatorFile::load($this->projectRoot);

        return $this->command->run($input, $output);
    }
}
