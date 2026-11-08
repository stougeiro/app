<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Domain;


final class View
{
    public function __construct(
        public readonly string $name,
        public readonly string $sql,
    ) {
    }


    /** @param array<string, mixed> $data */
    public static function fromArray(string $name, array $data): self
    {
        return new self(
            $name,
            is_string($data['sql'] ?? null) ? $data['sql'] : '',
        );
    }


    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sql' => $this->sql,
        ];
    }
}
