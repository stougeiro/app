<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Command;

use RuntimeException;
use Sourcerer\Tool\Contract\CommandInterface;
use Sourcerer\Tool\Contract\InputInterface;
use Sourcerer\Tool\Contract\OutputInterface;
use Sourcerer\Tool\Code\Config;
use Sourcerer\Tool\Code\Deployer;
use Sourcerer\Tool\Contract\Operator;
use Sourcerer\Tool\Contract\OperatorFile;
use Sourcerer\Tool\Code\Release\ReleaseSync;
use Sourcerer\Tool\Code\Shell;
use Sourcerer\Tool\Code\Target;


abstract class CommandAbstracted implements CommandInterface
{
    private ?Config $config = null;


    public function __construct(
        protected string $projectRoot,
        protected ?Shell $shell = null,
    ) {
    }


    public function usage(): string
    {
        $parts = ['sourcerer', $this->name()];

        foreach ($this->options() as $option) {
            $label = '--'. $option->name;

            if ($option->takesValue) {
                $label .= '=<'. $option->valueName .'>';
            }

            $parts[] = $option->required ? $label : '['. $label .']';
        }

        return implode(' ', $parts);
    }



    /** @return list<\Sourcerer\Tool\Contract\Option> */
    public function options(): array
    {
        return $this->commandOptions();
    }


    /** @return list<\Sourcerer\Tool\Contract\Option> */
    protected function commandOptions(): array
    {
        return [];
    }


    protected function config(): Config
    {
        return $this->config ??= Config::load($this->projectRoot .'/.sourcerer/code/config.json');
    }


    protected function operator(): Operator
    {
        return OperatorFile::load($this->projectRoot);
    }


    protected function target(InputInterface $input): Target
    {
        $name = $input->string('target');

        if ($name === null || $name === '') {
            throw new RuntimeException('--target is required');
        }

        return $this->config()->target($name);
    }


    protected function deployer(Target $target, OutputInterface $output): Deployer
    {
        return new Deployer(
            $this->projectRoot,
            $target,
            $this->shell(),
            $output,
            $this->operator(),
        );
    }


    protected function syncReleases(OutputInterface $output): bool
    {
        $results = (new ReleaseSync($this->projectRoot, $this->shell()))->syncAll($this->config());
        $ok = true;

        foreach ($results as $target => $result) {
            if ($result['ok']) {
                $output->line(
                    "[{$target}] sync ok ({$result['manifests']} manifests, {$result['events']} events)",
                );
                continue;
            }

            $ok = false;
            $output->warning("[{$target}] sync error: ". ($result['error'] ?? 'unknown error'));
        }

        return $ok;
    }


    protected function shell(): Shell
    {
        return $this->shell ??= new Shell();
    }
}
