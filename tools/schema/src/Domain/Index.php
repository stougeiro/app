<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Domain;


final class Index
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly bool $unique,
        public readonly string $source,
        public readonly bool $partial,
        public readonly ?string $sql = null,
    ) {
    }


    /** @param array<string, mixed> $data */
    public static function fromArray(string $key, array $data): self
    {
        $columns = [];

        foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $column) {
            if (is_string($column)) {
                $columns[] = $column;
            }
        }

        return new self(
            is_string($data['name'] ?? null) ? $data['name'] : $key,
            $columns,
            (bool) ($data['unique'] ?? false),
            is_string($data['source'] ?? null) ? $data['source'] : 'explicit',
            (bool) ($data['partial'] ?? false),
            is_string($data['sql'] ?? null) ? $data['sql'] : null,
        );
    }


    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'unique' => $this->unique,
            'source' => $this->source,
            'partial' => $this->partial,
            'sql' => $this->sql,
        ];
    }
}
