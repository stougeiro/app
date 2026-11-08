<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema;

use Sourcerer\Tool\Contract\CommandInterface;
use Sourcerer\Tool\Contract\ToolInterface;
use Sourcerer\Tool\Schema\Command\InitCommand;
use Sourcerer\Tool\Schema\Command\StatusCommand;


final class SchemaTool implements ToolInterface
{
    public const VERSION = '0.1.0';


    public function name(): string
    {
        return 'schema';
    }

    public function description(): string
    {
        return 'Structural database schema versioning tool';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /** @return array<string, CommandInterface> */
    public function commands(string $projectRoot): array
    {
        return [
            'schema init' => new InitCommand($projectRoot),
            'schema status' => new StatusCommand($projectRoot),
        ];
    }
}
