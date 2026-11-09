<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Command;

use RuntimeException;
use Sourcerer\Tool\Contract\ExitCode;
use Sourcerer\Tool\Contract\InputInterface;
use Sourcerer\Tool\Contract\Option;
use Sourcerer\Tool\Contract\OutputInterface;
use Sourcerer\Tool\Code\Config;
use Sourcerer\Tool\Code\Environment\EnvironmentChain;
use Sourcerer\Tool\Code\Promotion\Promoter;
use Sourcerer\Tool\Support\FileSystem;


final class PromoteCommand extends CommandAbstracted
{
    public function name(): string
    {
        return 'code promote';
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

        $config = Config::load($this->projectRoot .'/.sourcerer/code/config.json');
        $source = $config->target($from);

        $next = EnvironmentChain::next($from, $config->names());

        if ($next === null) {
            $output->info("nothing to promote from {$from}");

            return ExitCode::Success->value;
        }

        $destination = $config->target($next);
        $operator = $this->operator();
        $sourcePromoter = new Promoter($this->projectRoot, $source, $this->shell(), $output, $operator);
        $destinationPromoter = new Promoter($this->projectRoot, $destination, $this->shell(), $output, $operator);
        $release = $sourcePromoter->export();

        try {
            $destinationPromoter->receive($release['archive'], $release['manifest'], $source->name, $input->bool('force'));
            $sourcePromoter->retire($destination->url, $destination->name);
        } finally {
            if ($release['temporary']) {
                if ( ! FileSystem::tryDelete($release['archive'])) {
                    $output->warning('warning: could not remove temporary release archive');
                }

                if ( ! FileSystem::tryDelete(dirname($release['archive']) .'/manifest.json')) {
                    $output->warning('warning: could not remove temporary release manifest');
                }

                if ( ! FileSystem::tryDelete(dirname($release['archive']))) {
                    $output->warning('warning: could not remove temporary release directory');
                }
            }
        }

        $this->syncReleases($output);

        $output->success(
            "promoted {$release['manifest']->releaseId} from {$source->name} to {$destination->name}",
        );

        return ExitCode::Success->value;
    }
}
