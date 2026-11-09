<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code;

use Sourcerer\Tool\Contract\CommandInterface;
use Sourcerer\Tool\Contract\ToolInterface;
use Sourcerer\Tool\Code\Command\CheckCommand;
use Sourcerer\Tool\Code\Command\PromoteCommand;
use Sourcerer\Tool\Code\Command\PushCommand;
use Sourcerer\Tool\Code\Command\ReleasesCommand;
use Sourcerer\Tool\Code\Command\RollbackCommand;


final class CodeTool implements ToolInterface
{
    public const VERSION = '0.1.0';


    public function name(): string
    {
        return 'code';
    }

    public function description(): string
    {
        return 'Shared-host code delivery tool';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /** @return array<string, CommandInterface> */
    public function commands(string $projectRoot): array
    {
        return [
            'code check' => new CheckCommand($projectRoot),
            'code push' => new PushCommand($projectRoot),
            'code promote' => new PromoteCommand($projectRoot),
            'code releases' => new ReleasesCommand($projectRoot),
            'code rollback' => new RollbackCommand($projectRoot),
        ];
    }

    /** @return list<string> */
    public function install(string $projectRoot): array
    {
        return (new Initializer($projectRoot))->run();
    }
}
