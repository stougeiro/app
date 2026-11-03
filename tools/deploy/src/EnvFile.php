<?php declare(strict_types=1);

namespace Deploy;

use JsonException;
use RuntimeException;


class EnvFile
{
    private const MAX_BYTES = 64 * 1024;


    public static function path(string $projectRoot, string $environment): string
    {
        return rtrim($projectRoot, '/') .'/.deploy/env/'. $environment .'.json';
    }


    /**
     * @return array<string, mixed>
     */
    public static function load(string $file, string $environment): array
    {
        if ( ! is_file($file) || ! is_readable($file))
        {
            throw new RuntimeException("local env not found: {$file}");
        }

        if (is_link($file))
        {
            throw new RuntimeException("local env cannot be a symlink: {$file}");
        }

        $size = filesize($file);

        if ($size === false || $size > self::MAX_BYTES)
        {
            throw new RuntimeException("local env exceeds ". self::MAX_BYTES ." bytes: {$file}");
        }

        $raw = file_get_contents($file);

        if ($raw === false)
        {
            throw new RuntimeException("local env could not be read: {$file}");
        }

        try
        {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        catch (JsonException $error)
        {
            throw new RuntimeException('invalid local env: '. $error->getMessage(), 0, $error);
        }

        if ( ! is_array($data))
        {
            throw new RuntimeException('local env must be a JSON object');
        }

        $value = $data['environment'] ?? null;

        if ( ! is_string($value) || $value !== $environment)
        {
            throw new RuntimeException(
                "local env declares '". (is_string($value) ? $value : '?') ."', expected '{$environment}'",
            );
        }

        /** @var array<string, mixed> $data */
        return $data;
    }


    /**
     * @param array<string, mixed> $data
     */
    public static function hash(array $data): string
    {
        return hash('sha256', self::canonical($data));
    }


    /**
     * @param array<string, mixed> $local
     * @param array<string, mixed>|null $remote
     * @return array{added:list<string>,changed:list<string>,removed:list<string>}
     */
    public static function diff(array $local, ?array $remote): array
    {
        if ($remote === null)
        {
            return [
                'added' => array_keys(self::flatten($local)),
                'changed' => [],
                'removed' => [],
            ];
        }

        $left = self::flatten($local);
        $right = self::flatten($remote);

        $added = array_values(array_diff(array_keys($left), array_keys($right)));
        $removed = array_values(array_diff(array_keys($right), array_keys($left)));
        $changed = [];

        foreach (array_intersect(array_keys($left), array_keys($right)) as $key)
        {
            if ($left[$key] !== $right[$key])
            {
                $changed[] = $key;
            }
        }

        sort($added);
        sort($changed);
        sort($removed);

        return ['added' => $added, 'changed' => $changed, 'removed' => $removed];
    }


    private static function canonical(mixed $value): string
    {
        if ( ! is_array($value))
        {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (array_is_list($value))
        {
            $items = array_map(self::canonical(...), $value);

            return '['. implode(',', $items) .']';
        }

        ksort($value);
        $items = [];

        foreach ($value as $key => $item)
        {
            $items[] = json_encode((string) $key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                .':'. self::canonical($item);
        }

        return '{'. implode(',', $items) .'}';
    }


    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value)
        {
            $path = $prefix === '' ? (string) $key : $prefix .'.'. $key;

            if (is_array($value) && ! array_is_list($value))
            {
                $flat += self::flatten($value, $path);
                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }
}
