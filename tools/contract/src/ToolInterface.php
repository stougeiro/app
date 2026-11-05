<?php declare(strict_types=1);

namespace Tool\Contract;

use STDW\Contract\Container\ContainerInterface;


interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, CommandInterface> */
    public function commands(ContainerInterface $container): array;
}
