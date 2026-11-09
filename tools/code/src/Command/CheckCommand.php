<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Command;

use Sourcerer\Tool\Contract\ExitCode;
use Sourcerer\Tool\Contract\InputInterface;
use Sourcerer\Tool\Contract\Option;
use Sourcerer\Tool\Contract\OutputInterface;


final class CheckCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'code check';
    }

    public function description(): string
    {
        return 'Verify SSH, requirements, paths, storage and transport';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            new Option('target', 'Target to operate on (development, staging, production)', true, null, true, 'target'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $this->deployer($this->target($input), $output)->check();

        return ExitCode::Success->value;
    }
}
