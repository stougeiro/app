<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Domain;

use stdClass;


final class SchemaState
{
    /**
     * @param array<string, Table> $tables
     * @param array<string, View> $views
     * @param array<string, mixed> $sequences
     */
    public function __construct(
        public readonly array $tables,
        public readonly array $views,
        public readonly array $sequences = [],
    ) {
    }


    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $tables = [];

        foreach (is_array($data['tables'] ?? null) ? $data['tables'] : [] as $name => $table) {
            if (is_string($name) && is_array($table)) {
                /** @var array<string, mixed> $table */
                $tables[$name] = Table::fromArray($name, $table);
            }
        }

        $views = [];

        foreach (is_array($data['views'] ?? null) ? $data['views'] : [] as $name => $view) {
            if (is_string($name) && is_array($view)) {
                /** @var array<string, mixed> $view */
                $views[$name] = View::fromArray($name, $view);
            }
        }

        $sequences = is_array($data['sequences'] ?? null) ? $data['sequences'] : [];

        return new self($tables, $views, $sequences);
    }


    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tables = [];

        foreach ($this->tables as $name => $table) {
            $tables[$name] = $table->toArray();
        }

        $views = [];

        foreach ($this->views as $name => $view) {
            $views[$name] = $view->toArray();
        }

        return [
            'tables' => $tables === [] ? new stdClass() : $tables,
            'views' => $views === [] ? new stdClass() : $views,
            'sequences' => $this->sequences === [] ? new stdClass() : $this->sequences,
        ];
    }
}
