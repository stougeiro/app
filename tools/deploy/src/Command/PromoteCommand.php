<?php declare(strict_types=1);

namespace Tool\Deploy\Command;

use RuntimeException;
use Tool\Contract\ExitCode;
use Tool\Contract\InputInterface;
use Tool\Contract\Option;
use Tool\Contract\OutputInterface;
use Tool\Deploy\Config;
use Tool\Deploy\Deployer;
use Tool\Deploy\Environment\EnvironmentChain;


final class PromoteCommand extends DeployCommandAbstracted
{
    public function name(): string
    {
        return 'deploy promote';
    }

    public function description(): string
    {
        return 'Promote the current release to the next environment';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            new Option('from', 'Source target to promote (development or staging)', true, null, true, 'target'),
            new Option('force', 'Override an active production lock; recorded as an event'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $from = $input->string('from');

        if ($from === null || $from === '') {
            throw new RuntimeException('--from is required');
        }

        $config = Config::load($this->projectRoot .'/deploy.json');
        $source = $config->target($from);

        if ( ! in_array($source->name, Config::PROMOTION, true)) {
            throw new RuntimeException("target '{$source->name}' is not promotable");
        }

        $next = EnvironmentChain::next($from, $config->names());

        if ($next === null) {
            $output->info("nothing to promote from {$from}");

            return ExitCode::Success->value;
        }

        $destination = $config->target($next);
        $operator = $this->operator();
        $sourceDeployer = new Deployer($this->projectRoot, $source, $this->shell(), $output, $operator);
        $destinationDeployer = new Deployer($this->projectRoot, $destination, $this->shell(), $output, $operator);
        $release = $sourceDeployer->exportRelease();

        try {
            $destinationDeployer->receiveRelease($release['archive'], $release['manifest'], $source->name, $input->bool('force'));
            $sourceDeployer->retire($destination->url, $destination->name);
        } finally {
            if ($release['temporary']) {
                @unlink($release['archive']);
                @unlink(dirname($release['archive']) .'/manifest.json');
                @rmdir(dirname($release['archive']));
            }
        }

        $this->syncReleases($output);

        $output->success(
            "promoted {$release['manifest']->releaseId} from {$source->name} to {$destination->name}",
        );

        return ExitCode::Success->value;
    }
}
