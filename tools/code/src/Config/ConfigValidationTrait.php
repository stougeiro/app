<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Config;

use RuntimeException;


trait ConfigValidationTrait
{
    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function requireArray(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if ( ! is_array($value))
        {
            throw new RuntimeException("config: '{$key}' must be an object");
        }

        return $value;
    }


    /**
     * @param array<array-key, mixed> $data
     */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if ( ! is_string($value) || trim($value) === '')
        {
            throw new RuntimeException("config: '{$key}' must be a non-empty string");
        }

        return trim($value);
    }


    /**
     * @param array<array-key, mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null)
        {
            return null;
        }

        if ( ! is_string($value) || trim($value) === '')
        {
            throw new RuntimeException("config: '{$key}' must be a non-empty string");
        }

        return trim($value);
    }


    /**
     * @param array<array-key, mixed> $data
     */
    private static function optionalInt(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? $default;

        if ( ! is_int($value))
        {
            throw new RuntimeException("config: '{$key}' must be an integer");
        }

        return $value;
    }


    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if ( ! is_array($value))
        {
            throw new RuntimeException('config: invalid list');
        }

        $items = [];

        foreach ($value as $item)
        {
            if ( ! is_string($item))
            {
                throw new RuntimeException('config: list item must be a string');
            }

            $items[] = $item;
        }

        return $items;
    }


    /** @param array<array-key, mixed> $keep */
    private static function keep(array $keep, string $key, int $default): int
    {
        $value = $keep[$key] ?? $default;

        if ( ! is_int($value) || $value < 1) {
            throw new RuntimeException("config: 'keep.{$key}' must be an integer >= 1");
        }

        return $value;
    }


    private static function resolveAuthFile(string $file, string $root): string
    {
        if (str_starts_with($file, '~/') || str_starts_with($file, '/'))
        {
            return $file;
        }

        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $file)) as $segment)
        {
            if ($segment === '' || $segment === '.')
            {
                continue;
            }

            if ($segment === '..')
            {
                if ($segments === [])
                {
                    throw new RuntimeException("config: ssh.auth.file escapes project root: {$file}");
                }

                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        if ($segments === [])
        {
            throw new RuntimeException("config: invalid ssh.auth.file: {$file}");
        }

        return rtrim($root, '/') .'/'. implode('/', $segments);
    }
}
