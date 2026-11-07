<?php declare(strict_types=1);

namespace Tool\Deploy\Command;

use Tool\Contract\ExitCode;
use Tool\Contract\InputInterface;
use Tool\Contract\Option;
use Tool\Contract\OutputInterface;


final class CheckCommand extends DeployCommandAbstracted
{
    public function name(): string
    {
        return 'deploy check';
    }

    public function description(): string
    {
        return 'Verify SSH, requirements, paths, storage and transport';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            new Option('target', 'Target to operate on (development, staging, production, demonstration)', true, null, true, 'target'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $this->deployer($this->target($input), $output)->check();

        return ExitCode::Success->value;
    }
}
