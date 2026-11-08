<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Sql;

use Sourcerer\Tool\Schema\Domain\Column;
use Sourcerer\Tool\Schema\Domain\ForeignKey;
use Sourcerer\Tool\Schema\Domain\Index;
use Sourcerer\Tool\Schema\Domain\SchemaState;
use Sourcerer\Tool\Schema\Domain\Table;
use Sourcerer\Tool\Schema\Domain\View;


final class SqliteSqlGenerator
{
    public function release(SchemaState $state): string
    {
        $statements = [];

        foreach ($this->orderedTables($state->tables) as $table) {
            $statements[] = $this->table($table);
        }

        foreach ($state->tables as $table) {
            foreach ($table->indexes as $index) {
                if ($index->source !== 'explicit') {
                    continue;
                }

                $statements[] = $this->index($table, $index);
            }
        }

        foreach ($state->views as $view) {
            $statements[] = $this->view($view);
        }

        return implode(";\n\n", $statements) .";\n";
    }


    /**
     * @param array<string, Table> $tables
     * @return list<Table>
     */
    private function orderedTables(array $tables): array
    {
        $remaining = $tables;
        $ordered = [];

        while ($remaining !== []) {
            $progress = false;

            foreach ($remaining as $name => $table) {
                $ready = true;

                foreach ($table->foreignKeys as $foreignKey) {
                    $referenced = $foreignKey->referencedTable;

                    if ($referenced !== $name && isset($remaining[$referenced])) {
                        $ready = false;
                        break;
                    }
                }

                if ( ! $ready) {
                    continue;
                }

                $ordered[] = $table;
                unset($remaining[$name]);
                $progress = true;
            }

            if ( ! $progress) {
                ksort($remaining, SORT_STRING);

                foreach ($remaining as $table) {
                    $ordered[] = $table;
                }

                break;
            }
        }

        return $ordered;
    }


    private function table(Table $table): string
    {
        $lines = [];
        $primary = $table->primaryKey;
        $autoIncrement = false;

        foreach ($table->orderedColumns() as $column) {
            $autoIncrement = $autoIncrement || $column->autoIncrement;
        }

        foreach ($table->orderedColumns() as $column) {
            $lines[] = '    '. $this->column($column);
        }

        if ( ! $autoIncrement && $primary !== []) {
            $lines[] = '    PRIMARY KEY ('. $this->columns($primary) .')';
        }

        foreach ($table->indexes as $index) {
            if ($index->source !== 'constraint' || $index->columns === []) {
                continue;
            }

            $lines[] = '    UNIQUE ('. $this->columns($index->columns) .')';
        }

        foreach ($table->foreignKeys as $foreignKey) {
            $lines[] = '    '. $this->foreignKey($foreignKey);
        }

        return 'CREATE TABLE '. $this->name($table->name) ." (\n"
            . implode(",\n", $lines)
            ."\n)";
    }


    private function column(Column $column): string
    {
        $definition = $this->name($column->name);

        if (trim($column->type) !== '') {
            $definition .= ' '. trim($column->type);
        }

        if ($column->autoIncrement) {
            return $definition .' PRIMARY KEY AUTOINCREMENT';
        }

        if ( ! $column->nullable) {
            $definition .= ' NOT NULL';
        }

        if ($column->default !== null) {
            $definition .= ' DEFAULT '. $column->default;
        }

        return $definition;
    }


    private function foreignKey(ForeignKey $foreignKey): string
    {
        $sql = 'FOREIGN KEY ('. $this->columns($foreignKey->columns) .') REFERENCES '
            . $this->name($foreignKey->referencedTable);

        if ($foreignKey->referencedColumns !== []) {
            $sql .= ' ('. $this->columns($foreignKey->referencedColumns) .')';
        }

        if ($foreignKey->onDelete !== '') {
            $sql .= ' ON DELETE '. $foreignKey->onDelete;
        }

        if ($foreignKey->onUpdate !== '') {
            $sql .= ' ON UPDATE '. $foreignKey->onUpdate;
        }

        if ($foreignKey->match !== '' && strtoupper($foreignKey->match) !== 'NONE') {
            $sql .= ' MATCH '. $foreignKey->match;
        }

        return $sql;
    }


    private function index(Table $table, Index $index): string
    {
        if ($index->sql !== null && trim($index->sql) !== '') {
            return rtrim($index->sql, ';');
        }

        $unique = $index->unique ? 'UNIQUE ' : '';

        return 'CREATE '. $unique .'INDEX '. $this->name($index->name)
            .' ON '. $this->name($table->name)
            .' ('. $this->columns($index->columns) .')';
    }


    private function view(View $view): string
    {
        return rtrim($view->sql, ';');
    }


    /** @param list<string> $columns */
    private function columns(array $columns): string
    {
        return implode(', ', array_map($this->name(...), $columns));
    }


    private function name(string $name): string
    {
        return '"'. str_replace('"', '""', $name) .'"';
    }
}
