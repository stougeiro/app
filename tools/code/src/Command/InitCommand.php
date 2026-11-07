<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Command;

use Sourcerer\Tool\Contract\ExitCode;
use Sourcerer\Tool\Contract\InputInterface;
use Sourcerer\Tool\Contract\OutputInterface;
use Sourcerer\Tool\Code\Initializer;


final class InitCommand extends CommandAbstracted
{
    public function name(): string
    {
        return 'code init';
    }

    public function description(): string
    {
        return 'Create local deploy directories, stubs and configuration';
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        (new Initializer($this->projectRoot, $output))->run();

        return ExitCode::Success->value;
    }
}
