<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Command;

use Sourcerer\Tool\Contract\ExitCode;
use Sourcerer\Tool\Contract\InputInterface;
use Sourcerer\Tool\Contract\Option;
use Sourcerer\Tool\Contract\OutputInterface;


final class PushCommand extends CommandAbstracted
{
    public function name(): string
    {
        return 'code push';
    }

    public function description(): string
    {
        return 'Build a release, back up, transfer and verify a deployment';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            new Option('target', 'Target to operate on (development, staging, production, demonstration)', true, null, true, 'target'),
            new Option('force', 'Override an active production lock; recorded as an event'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $this->deployer($this->target($input), $output)->push($input->bool('force'));
        $this->syncReleases($output);

        return ExitCode::Success->value;
    }
}
