<?php declare(strict_types=1);

namespace Sourcerer\Tool\Support;

use ErrorException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;


final class FileSystem
{
    public static function ensureDirectory(string $path, int $mode = 0700): void
    {
        if (is_dir($path)) {
            return;
        }

        if (file_exists($path)) {
            throw new RuntimeException("not a directory: {$path}");
        }

        try {
            self::guard(static fn () => mkdir($path, $mode, true));
        } catch (Throwable $error) {
            if (is_dir($path)) {
                return;
            }

            throw new RuntimeException("could not create directory: {$path}", 0, $error);
        }
    }


    public static function write(string $path, string $contents, int $mode = 0644): void
    {
        self::ensureDirectory(dirname($path));

        $temporary = self::guard(static fn () => tempnam(dirname($path), '.sourcerer-'));

        if ( ! is_string($temporary)) {
            throw new RuntimeException("could not create temporary file for {$path}");
        }

        try {
            self::guard(static fn () => file_put_contents($temporary, $contents));
            self::chmod($temporary, $mode);

            if ( ! self::guard(static fn () => rename($temporary, $path))) {
                throw new RuntimeException("could not move temporary file to {$path}");
            }
        } catch (Throwable $error) {
            self::tryDelete($temporary);
            throw $error;
        }
    }


    public static function copyFile(string $source, string $destination): void
    {
        if ( ! is_file($source)) {
            throw new RuntimeException("missing file: {$source}");
        }

        self::ensureDirectory(dirname($destination));

        if ( ! self::guard(static fn () => copy($source, $destination))) {
            throw new RuntimeException("could not copy {$source} to {$destination}");
        }
    }


    /**
     * @param list<string> $excludeTop
     */
    public static function copyDir(string $source, string $destination, array $excludeTop = []): void
    {
        if ( ! is_dir($source)) {
            throw new RuntimeException("missing directory: {$source}");
        }

        self::ensureDirectory($destination);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ( ! $item instanceof SplFileInfo || $item->isLink()) {
                continue;
            }

            $relative = substr($item->getPathname(), strlen($source) + 1);

            if ($relative === '') {
                continue;
            }

            $top = explode(DIRECTORY_SEPARATOR, $relative)[0];

            if (in_array($top, $excludeTop, true)) {
                continue;
            }

            $path = $destination .'/'. $relative;

            if ($item->isDir()) {
                self::ensureDirectory($path);
                continue;
            }

            if ($item->isFile()) {
                self::copyFile($item->getPathname(), $path);
            }
        }
    }


    public static function removeDir(string $directory): void
    {
        if ( ! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ( ! $item instanceof SplFileInfo || $item->isLink()) {
                continue;
            }

            if ($item->isDir()) {
                if ( ! self::guard(static fn () => rmdir($item->getPathname()))) {
                    throw new RuntimeException("could not remove directory {$item->getPathname()}");
                }

                continue;
            }

            if ( ! self::guard(static fn () => unlink($item->getPathname()))) {
                throw new RuntimeException("could not remove file {$item->getPathname()}");
            }
        }

        if ( ! self::guard(static fn () => rmdir($directory))) {
            throw new RuntimeException("could not remove directory {$directory}");
        }
    }


    public static function delete(string $path): void
    {
        if ( ! file_exists($path) && ! is_link($path)) {
            return;
        }

        if (is_dir($path) && ! is_link($path)) {
            self::removeDir($path);
            return;
        }

        if ( ! self::guard(static fn () => unlink($path))) {
            throw new RuntimeException("could not delete {$path}");
        }
    }


    public static function tryDelete(string $path): bool
    {
        try {
            self::delete($path);

            return true;
        } catch (Throwable) {
            return false;
        }
    }


    public static function chmod(string $path, int $mode): void
    {
        if ( ! self::guard(static fn () => chmod($path, $mode))) {
            throw new RuntimeException("could not chmod {$path}");
        }
    }


    public static function temporaryDirectory(string $prefix = 'sourcerer-'): string
    {
        $directory = sys_get_temp_dir() .'/'. $prefix . bin2hex(random_bytes(6));

        self::ensureDirectory($directory);

        return $directory;
    }


    public static function temporaryArchive(string $prefix = 'sourcerer-'): string
    {
        return sys_get_temp_dir() .'/'. $prefix . bin2hex(random_bytes(6)) .'.tar.gz';
    }


    private static function guard(callable $operation): mixed
    {
        set_error_handler(static function (
            int $severity,
            string $message,
            string $file,
            int $line,
        ): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
