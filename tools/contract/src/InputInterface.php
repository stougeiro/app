<?php declare(strict_types=1);

namespace Tool\Contract;


interface InputInterface
{
    public function has(string $name): bool;

    public function bool(string $name): bool;

    public function string(string $name, ?string $default = null): ?string;

    public function int(string $name, int $default): int;

    /** @return list<string> */
    public function arguments(): array;
}
