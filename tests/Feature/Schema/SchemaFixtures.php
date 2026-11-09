<?php declare(strict_types=1);

namespace Tests\Feature\Schema;

use PDO;
use RuntimeException;
use Sourcerer\Tool\Contract\CommandInterface;
use Sourcerer\Tool\Contract\InputInterface;
use Sourcerer\Tool\Manager\Input;
use Sourcerer\Tool\Manager\Output;


final class SchemaFixtures
{
    /** @return array<string, mixed> */
    public static function config(string $database = 'application/storage/database.sqlite'): array
    {
        return [
            'databases' => [
                'default' => [
                    'driver' => 'sqlite',
                    'version' => '3',
                    'targets' => [
                        'local' => [
                            'transport' => 'local',
                            'database' => $database,
                        ],
                    ],
                ],
            ],
        ];
    }


    public static function workspace(?string $database = null): string
    {
        $root = sys_get_temp_dir() .'/schema-'. bin2hex(random_bytes(6));

        if ( ! mkdir($root, 0755, true) && ! is_dir($root)) {
            throw new RuntimeException('could not create schema workspace');
        }

        mkdir($root .'/.sourcerer/schema', 0755, true);

        file_put_contents(
            $root .'/.sourcerer/schema/config.json',
            json_encode(self::config($database ?? 'storage/database.sqlite'), JSON_THROW_ON_ERROR),
        );

        return $root;
    }


    public static function sqlite(string $path, string $sql = ''): PDO
    {
        $directory = dirname($path);

        if ( ! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('could not create sqlite directory');
        }

        $pdo = new PDO('sqlite:'. $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        if (trim($sql) !== '') {
            $pdo->exec($sql);
        }

        return $pdo;
    }


    public static function input(CommandInterface $command, array $tokens): InputInterface
    {
        return Input::parse($tokens, $command->options());
    }


    /** @return array{output: Output, stream: resource} */
    public static function output(): array
    {
        $stream = fopen('php://memory', 'w+');

        if ($stream === false) {
            throw new RuntimeException('could not open output stream');
        }

        return ['output' => new Output($stream), 'stream' => $stream];
    }


    /** @param resource $stream */
    public static function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
