<?php declare(strict_types=1);

namespace Tool\Deploy\Command;

use RuntimeException;
use Tool\Contract\ExitCode;
use Tool\Contract\InputInterface;
use Tool\Contract\Option;
use Tool\Contract\OutputInterface;


final class RollbackCommand extends DeployCommandAbstracted
{
    public function name(): string
    {
        return 'deploy rollback';
    }

    public function description(): string
    {
        return 'List backups or restore a deployment backup';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            new Option('target', 'Target to operate on (development, staging, production, demonstration)', true, null, true, 'target'),
            new Option('list', 'List backups instead of restoring'),
            new Option('timestamp', 'Backup timestamp (Ymd-His)', true, null, false, 'Ymd-His'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $target = $this->target($input);
        $deployer = $this->deployer($target, $output);

        if ($input->bool('list')) {
            if ($input->has('timestamp')) {
                throw new RuntimeException('--list and --timestamp cannot be used together');
            }

            $backups = $deployer->backups();

            if ($backups === []) {
                $output->line('['. $target->name .'] no backups');

                return ExitCode::Success->value;
            }

            $output->line('['. $target->name .'] backups:');

            foreach ($backups as $backup) {
                $output->line($backup);
            }

            return ExitCode::Success->value;
        }

        $timestamp = $input->string('timestamp');

        if ($input->has('timestamp') && ($timestamp === null || $timestamp === '')) {
            throw new RuntimeException('--timestamp requires a value');
        }

        $deployer->rollback($timestamp);

        return ExitCode::Success->value;
    }
}
