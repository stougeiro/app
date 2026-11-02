<?php declare(strict_types=1);

namespace App\Core\Log;

use Throwable;


final class PhpErrorLog
{
    public static function configure(string $path): bool
    {
        if ( ! function_exists('ini_set') || ! function_exists('ini_get')) {
            return false;
        }

        $disabled = array_filter(array_map(
            static fn (string $name): string => trim($name),
            explode(',', (string) ini_get('disable_functions')),
        ));

        if (in_array('ini_set', $disabled, true)) {
            return false;
        }

        $directory = dirname($path);

        if ( ! is_dir($directory) || ! is_writable($directory)) {
            return false;
        }

        if (is_file($path) && ! is_writable($path)) {
            return false;
        }

        $originalValue = ini_get('error_log');

        if ($originalValue === $path) {
            return true;
        }

        try {
            $result = ini_set('error_log', $path);
        } catch (Throwable) {
            return false;
        }

        if ($result === false) {
            return false;
        }

        if (ini_get('error_log') !== $path) {
            if (is_string($originalValue) && $originalValue !== '') {
                try {
                    ini_set('error_log', $originalValue);
                } catch (Throwable) {
                    // The host controls error_log; there is nothing else to do.
                }
            }

            return false;
        }

        return true;
    }
}
