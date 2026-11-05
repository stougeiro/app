<?php declare(strict_types=1);

namespace Tool\Deploy\Command;

use RuntimeException;
use Tool\Contract\CommandInterface;
use Tool\Contract\InputInterface;
use Tool\Contract\OutputInterface;
use Tool\Deploy\Config;
use Tool\Deploy\Deployer;
use Tool\Deploy\Shell;
use Tool\Deploy\Target;


abstract class DeployCommandAbstracted implements CommandInterface
{
    public function __construct(
        protected string $projectRoot,
        protected ?Shell $shell = null,
    ) {
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


    protected function target(InputInterface $input): Target
    {
        $name = $input->string('target');

        if ($name === null || $name === '') {
            throw new RuntimeException('--target is required');
        }

        return Config::load($this->projectRoot .'/deploy.json')->target($name);
    }


    protected function deployer(Target $target, OutputInterface $output): Deployer
    {
        return new Deployer($this->projectRoot, $target, $this->shell(), $output);
    }


    protected function shell(): Shell
    {
        return $this->shell ??= new Shell();
    }
}
