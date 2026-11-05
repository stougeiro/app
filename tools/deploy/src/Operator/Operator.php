<?php declare(strict_types=1);

namespace Tool\Deploy\Operator;


final class Operator
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $email = null,
    ) {
    }


    /** @return array{name: string, email: ?string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }
}
