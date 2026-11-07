<?php declare(strict_types=1);

namespace App\Core\Log;

use Throwable;


class PhpErrorLog
{
    public static function configure(string $path): bool
    {
        if ( ! static::doFunctionExists('ini_set') || ! static::doFunctionExists('ini_get')) {
            return false;
        }

        $disabled = array_filter(array_map(
            static fn (string $name): string => trim($name),
            explode(',', (string) static::doIniGet('disable_functions')),
        ));

        if (in_array('ini_set', $disabled, true)) {
            return false;
        }

        $directory = dirname($path);

        if ( ! static::doIsDir($directory) || ! static::doIsWritable($directory)) {
            return false;
        }

        if (static::doIsFile($path) && ! static::doIsWritable($path)) {
            return false;
        }

        $originalValue = static::doIniGet('error_log');

        if ($originalValue === $path) {
            return true;
        }

        try {
            $result = static::doIniSet('error_log', $path);
        } catch (Throwable) {
            return false;
        }

        if ($result === false) {
            return false;
        }

        if (static::doIniGet('error_log') !== $path) {
            if (is_string($originalValue) && $originalValue !== '') {
                try {
                    static::doIniSet('error_log', $originalValue);
                } catch (Throwable) {
                    // The host controls error_log; there is nothing else to do.
                }
            }

            return false;
        }

        return true;
    }


    protected static function doFunctionExists(string $name): bool
    {
        return function_exists($name);
    }

    protected static function doIniGet(string $name): string|false
    {
        return ini_get($name);
    }

    protected static function doIniSet(string $name, string $value): string|false
    {
        return ini_set($name, $value);
    }

    protected static function doIsDir(string $path): bool
    {
        return is_dir($path);
    }

    protected static function doIsFile(string $path): bool
    {
        return is_file($path);
    }

    protected static function doIsWritable(string $path): bool
    {
        return is_writable($path);
    }
}
