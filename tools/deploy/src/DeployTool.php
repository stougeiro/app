<?php declare(strict_types=1);

namespace Tool\Deploy;

use STDW\Contract\Container\ContainerInterface;
use Tool\Contract\CommandInterface;
use Tool\Contract\ToolInterface;
use Tool\Deploy\Command\CheckCommand;
use Tool\Deploy\Command\InitCommand;
use Tool\Deploy\Command\PushCommand;
use Tool\Deploy\Command\RollbackCommand;


final class DeployTool implements ToolInterface
{
    public function __construct(
        protected string $projectRoot = '',
    ) {
        $this->projectRoot = $projectRoot !== '' ? $projectRoot : dirname(__DIR__, 3);
    }


    public function name(): string
    {
        return 'deploy';
    }

    public function description(): string
    {
        return 'SSH deployment tool';
    }

    /** @return array<string, CommandInterface> */
    public function commands(ContainerInterface $container): array
    {
        return [
            'deploy init' => new InitCommand($this->projectRoot),
            'deploy check' => new CheckCommand($this->projectRoot),
            'deploy push' => new PushCommand($this->projectRoot),
            'deploy rollback' => new RollbackCommand($this->projectRoot),
        ];
    }
}
