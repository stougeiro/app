<?php declare(strict_types=1);

namespace Tool\Deploy\Command;

use RuntimeException;
use Tool\Contract\CommandInterface;
use Tool\Contract\InputInterface;
use Tool\Contract\OutputInterface;
use Tool\Deploy\Config;
use Tool\Deploy\Deployer;
use Tool\Deploy\Operator\Operator;
use Tool\Deploy\Release\ReleaseSync;
use Tool\Deploy\Shell;
use Tool\Deploy\Target;


abstract class DeployCommandAbstracted implements CommandInterface
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



    /** @return list<\Tool\Contract\Option> */
    public function options(): array
    {
        return $this->commandOptions();
    }


    /** @return list<\Tool\Contract\Option> */
    protected function commandOptions(): array
    {
        return [];
    }


    protected function config(): Config
    {
        return $this->config ??= Config::load($this->projectRoot .'/deploy.json');
    }


    protected function operator(): Operator
    {
        return $this->config()->requireOperator();
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
