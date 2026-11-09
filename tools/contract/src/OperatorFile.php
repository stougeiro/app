<?php declare(strict_types=1);

namespace Sourcerer\Tool\Contract;

use JsonException;
use RuntimeException;
use Sourcerer\Tool\Support\FileSystem;


final class OperatorFile
{
    public static function path(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') .'/.sourcerer/operator.json';
    }


    public static function exists(string $projectRoot): bool
    {
        return is_file(self::path($projectRoot));
    }


    public static function read(string $projectRoot): ?Operator
    {
        $file = self::path($projectRoot);

        if ( ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $raw = file_get_contents($file);

        if ($raw === false) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if ( ! is_array($data)) {
            return null;
        }

        $name = $data['name'] ?? null;
        $email = $data['email'] ?? null;

        if ( ! is_string($name) || trim($name) === '' || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return new Operator(trim($name), trim($email));
    }


    public static function load(string $projectRoot): Operator
    {
        $operator = self::read($projectRoot);

        if ($operator === null) {
            throw new RuntimeException(
                'operator not configured; run ./sourcerer operator --name="..." --email="..."',
            );
        }

        return $operator;
    }


    public static function save(string $projectRoot, Operator $operator): void
    {
        try {
            $json = json_encode(
                $operator->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) ."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('could not encode operator: '. $exception->getMessage(), 0, $exception);
        }

        FileSystem::write(self::path($projectRoot), $json, 0600);
    }
}
