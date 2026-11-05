<?php declare(strict_types=1);

namespace Tool\Contract;


interface CommandInterface
{
    public function name(): string;

    public function description(): string;

    public function usage(): string;

    /** @return list<Option> */
    public function options(): array;

    public function run(InputInterface $input, OutputInterface $output): int;
}
