<?php declare(strict_types=1);

namespace Tool\Deploy\Command;

use Tool\Contract\ExitCode;
use Tool\Contract\InputInterface;
use Tool\Contract\Option;
use Tool\Contract\OutputInterface;


final class PushCommand extends DeployCommandAbstracted
{
    public function name(): string
    {
        return 'deploy push';
    }

    public function description(): string
    {
        return 'Build, back up, transfer and verify a deployment';
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
        $this->deployer($this->target($input), $output)->push();

        return ExitCode::Success->value;
    }
}
