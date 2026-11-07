<?php declare(strict_types=1);

namespace Sourcerer\Tool\Contract;


interface OutputInterface
{
    public function line(string $text = ''): void;

    public function info(string $text): void;

    public function success(string $text): void;

    public function warning(string $text): void;

    public function error(string $text): void;

    public function separator(): void;
}
