<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Driver;

use PDO;
use RuntimeException;
use Sourcerer\Tool\Schema\Domain\Column;
use Sourcerer\Tool\Schema\Domain\ForeignKey;
use Sourcerer\Tool\Schema\Domain\Index;
use Sourcerer\Tool\Schema\Domain\SchemaState;
use Sourcerer\Tool\Schema\Domain\Table;
use Sourcerer\Tool\Schema\Domain\View;


final class SqliteDriver implements DriverInterface
{
    public function driver(): string
    {
        return 'sqlite';
    }


    public function version(PDO $pdo): string
    {
        $statement = $pdo->query('SELECT sqlite_version()');

        if ($statement === false) {
            throw new RuntimeException('schema: could not read sqlite version');
        }

        $version = $statement->fetchColumn();

        return is_string($version) ? $version : '';
    }


    /** @return list<string> */
    public function unsupportedObjects(PDO $pdo): array
    {
        $rows = self::rows(
            $pdo,
            "SELECT name FROM sqlite_master WHERE type = 'trigger' ORDER BY name",
        );

        $objects = [];

        foreach ($rows as $row) {
            $name = self::string($row, 'name');

            if ($name !== '') {
                $objects[] = 'trigger '. $name;
            }
        }

        return $objects;
    }


    public function inspect(PDO $pdo): SchemaState
    {
        $tables = [];
        $views = [];

        $objects = self::rows(
            $pdo,
            "SELECT type, name, sql FROM sqlite_master WHERE type IN ('table', 'view') ORDER BY name",
        );

        foreach ($objects as $object) {
            $type = self::string($object, 'type');
            $name = self::string($object, 'name');

            if ($name === '' || str_starts_with($name, 'sqlite_')) {
                continue;
            }

            if ($type === 'view') {
                $views[$name] = new View($name, self::normalizeSql(self::string($object, 'sql')));
                continue;
            }

            $tables[$name] = $this->table($pdo, $name, self::string($object, 'sql'));
        }

        ksort($tables, SORT_STRING);
        ksort($views, SORT_STRING);

        return new SchemaState($tables, $views);
    }


    private function table(PDO $pdo, string $name, string $sql): Table
    {
        $rows = self::rows($pdo, 'PRAGMA table_xinfo('. $pdo->quote($name) .')');

        $primaryKey = [];

        foreach ($rows as $row) {
            $pk = self::int($row, 'pk');

            if ($pk > 0) {
                $primaryKey[$pk] = self::string($row, 'name');
            }
        }

        ksort($primaryKey);

        $primaryColumns = array_values($primaryKey);
        $autoIncrement = count($primaryColumns) === 1
            && preg_match('/\bAUTOINCREMENT\b/i', $sql) === 1;

        $columns = [];

        foreach ($rows as $row) {
            if (self::int($row, 'hidden') !== 0) {
                continue;
            }

            $columnName = self::string($row, 'name');

            if ($columnName === '') {
                continue;
            }

            $default = $row['dflt_value'] ?? null;

            $columns[$columnName] = new Column(
                $columnName,
                self::string($row, 'type'),
                self::int($row, 'notnull') === 0,
                $default === null ? null : (is_scalar($default) ? (string) $default : null),
                self::int($row, 'cid'),
                $autoIncrement && $columnName === $primaryColumns[0],
            );
        }

        return new Table(
            $name,
            $columns,
            $primaryColumns,
            $this->indexes($pdo, $name),
            $this->foreignKeys($pdo, $name),
        );
    }


    /**
     * @return array<string, Index>
     */
    private function indexes(PDO $pdo, string $table): array
    {
        $rows = self::rows($pdo, 'PRAGMA index_list('. $pdo->quote($table) .')');

        $indexes = [];

        foreach ($rows as $row) {
            $origin = self::string($row, 'origin');

            if ($origin === 'pk') {
                continue;
            }

            $indexName = self::string($row, 'name');

            if ($indexName === '') {
                continue;
            }

            $columns = $this->indexColumns($pdo, $indexName);
            $source = $origin === 'u' ? 'constraint' : 'explicit';
            $key = $source === 'constraint'
                ? 'constraint:'. implode(',', $columns)
                : $indexName;

            if (isset($indexes[$key])) {
                $key .= '#'. $indexName;
            }

            $indexes[$key] = new Index(
                $indexName,
                $columns,
                self::int($row, 'unique') === 1,
                $source,
                self::int($row, 'partial') === 1,
                $source === 'explicit' ? $this->indexSql($pdo, $indexName) : null,
            );
        }

        ksort($indexes, SORT_STRING);

        return $indexes;
    }


    /** @return list<string> */
    private function indexColumns(PDO $pdo, string $index): array
    {
        $rows = self::rows($pdo, 'PRAGMA index_info('. $pdo->quote($index) .')');

        $columns = [];

        foreach ($rows as $row) {
            $name = $row['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $columns[] = $name;
            }
        }

        return $columns;
    }


    private function indexSql(PDO $pdo, string $index): ?string
    {
        $statement = $pdo->prepare(
            "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = :name",
        );

        if ($statement === false) {
            throw new RuntimeException('schema: could not prepare index lookup');
        }

        $statement->execute(['name' => $index]);

        $sql = $statement->fetchColumn();

        return is_string($sql) && trim($sql) !== ''
            ? self::normalizeSql($sql)
            : null;
    }


    /**
     * @return array<string, ForeignKey>
     */
    private function foreignKeys(PDO $pdo, string $table): array
    {
        $rows = self::rows($pdo, 'PRAGMA foreign_key_list('. $pdo->quote($table) .')');

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[self::int($row, 'id')][] = $row;
        }

        $foreignKeys = [];

        foreach ($grouped as $id => $items) {
            usort(
                $items,
                static fn (array $left, array $right): int => self::int($left, 'seq') <=> self::int($right, 'seq'),
            );

            $first = $items[0];
            $columns = [];
            $referencedColumns = [];

            foreach ($items as $item) {
                $columns[] = self::string($item, 'from');
                $to = $item['to'] ?? null;

                if (is_string($to) && $to !== '') {
                    $referencedColumns[] = $to;
                }
            }

            $referencedTable = self::string($first, 'table');
            $key = 'fk:'. implode(',', $columns) .'->'. $referencedTable .'('. implode(',', $referencedColumns) .')';

            if (isset($foreignKeys[$key])) {
                $key .= '#'. $id;
            }

            $foreignKeys[$key] = new ForeignKey(
                $key,
                $columns,
                $referencedTable,
                $referencedColumns,
                self::string($first, 'on_delete'),
                self::string($first, 'on_update'),
                self::string($first, 'match'),
            );
        }

        ksort($foreignKeys, SORT_STRING);

        return $foreignKeys;
    }


    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);

        if ($statement === false) {
            throw new RuntimeException('schema: could not execute query: '. $sql);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }


    private static function normalizeSql(string $sql): string
    {
        $sql = str_replace(["\r\n", "\r"], "\n", trim($sql));

        return rtrim($sql, "; \t\n\r\0\x0B");
    }


    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : '';
    }


    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
