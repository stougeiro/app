<?php declare(strict_types=1);

namespace Tool\Deploy;


class ShellResult
{
    public function __construct(
        public readonly int $code,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }


    public function ok(): bool
    {
        return $this->code === 0;
    }


    public function output(): string
    {
        return trim($this->stdout);
    }
}
