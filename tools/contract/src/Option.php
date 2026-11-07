<?php declare(strict_types=1);

namespace Sourcerer\Tool\Contract;


final class Option
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly bool $takesValue = false,
        public readonly null|string|bool $default = null,
        public readonly bool $required = false,
        public readonly string $valueName = 'value',
    ) {
    }
}
