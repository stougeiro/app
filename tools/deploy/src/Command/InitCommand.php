<?php declare(strict_types=1);

namespace Tool\Deploy\Command;

use Tool\Contract\ExitCode;
use Tool\Contract\InputInterface;
use Tool\Contract\OutputInterface;
use Tool\Deploy\Initializer;


final class InitCommand extends DeployCommandAbstracted
{
    public function name(): string
    {
        return 'deploy init';
    }

    public function description(): string
    {
        return 'Create local deploy directories and configuration';
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        (new Initializer($this->projectRoot, $output))->run();

        return ExitCode::Success->value;
    }
}
