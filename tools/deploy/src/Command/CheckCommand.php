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
        return 'Verify SSH, paths, storage and transport';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            new Option('target', 'Deploy target', true),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $this->deployer($this->target($input), $output)->check();

        return ExitCode::Success->value;
    }
}
