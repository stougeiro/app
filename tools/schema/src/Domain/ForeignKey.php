<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Domain;


final class ForeignKey
{
    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly string $referencedTable,
        public readonly array $referencedColumns,
        public readonly string $onDelete,
        public readonly string $onUpdate,
        public readonly string $match,
    ) {
    }


    /** @param array<string, mixed> $data */
    public static function fromArray(string $key, array $data): self
    {
        return new self(
            $key,
            self::strings($data['columns'] ?? null),
            is_string($data['referencedTable'] ?? null) ? $data['referencedTable'] : '',
            self::strings($data['referencedColumns'] ?? null),
            is_string($data['onDelete'] ?? null) ? $data['onDelete'] : '',
            is_string($data['onUpdate'] ?? null) ? $data['onUpdate'] : '',
            is_string($data['match'] ?? null) ? $data['match'] : '',
        );
    }


    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'columns' => $this->columns,
            'referencedTable' => $this->referencedTable,
            'referencedColumns' => $this->referencedColumns,
            'onDelete' => $this->onDelete,
            'onUpdate' => $this->onUpdate,
            'match' => $this->match,
        ];
    }


    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        $items = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
