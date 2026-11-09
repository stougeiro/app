<?php declare(strict_types=1);

namespace Sourcerer\Tool\Contract;


interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    public function version(): string;

    /** @return array<string, CommandInterface> */
    public function commands(string $projectRoot): array;

    /** @return list<string> */
    public function install(string $projectRoot): array;
}
