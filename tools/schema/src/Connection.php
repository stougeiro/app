<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema;

use PDO;
use RuntimeException;
use Sourcerer\Tool\Schema\Config\DatabaseConfig;
use Sourcerer\Tool\Schema\Config\TargetConfig;


final class Connection
{
    public static function connect(
        string $projectRoot,
        DatabaseConfig $database,
        TargetConfig $target,
        bool $mustExist = false,
    ): PDO {
        if ( ! $target->isLocal()) {
            throw new RuntimeException('schema: ssh transport is not implemented yet');
        }

        if ($database->driver !== 'sqlite') {
            throw new RuntimeException("schema: driver '{$database->driver}' is not implemented yet");
        }

        $path = self::absolutePath($projectRoot, $target->database);

        if ($mustExist && ! is_file($path)) {
            throw new RuntimeException("schema: sqlite database not found: {$path}");
        }

        $pdo = new PDO('sqlite:'. $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }


    public static function absolutePath(string $projectRoot, string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return rtrim($projectRoot, '/') .'/'. ltrim($path, '/');
    }
}
