<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;


final class FileSystem
{
    public static function copyFile(string $source, string $destination): void
    {
        if ( ! is_file($source) || ! copy($source, $destination))
        {
            throw new RuntimeException("could not copy {$source}");
        }

        chmod($destination, 0644);
    }


    /**
     * @param list<string> $excludeTop
     */
    public static function copyDir(string $source, string $destination, array $excludeTop = []): void
    {
        if ( ! is_dir($source))
        {
            throw new RuntimeException("missing directory: {$source}");
        }

        if ( ! mkdir($destination, 0700, true) && ! is_dir($destination))
        {
            throw new RuntimeException("could not create {$destination}");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item)
        {
            if ( ! $item instanceof SplFileInfo || $item->isLink())
            {
                continue;
            }

            $relative = substr($item->getPathname(), strlen($source) + 1);

            if ($relative === '')
            {
                continue;
            }

            $top = explode(DIRECTORY_SEPARATOR, $relative)[0];

            if (in_array($top, $excludeTop, true))
            {
                continue;
            }

            $path = $destination .'/'. $relative;

            if ($item->isDir())
            {
                if ( ! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path))
                {
                    throw new RuntimeException("could not create {$path}");
                }

                continue;
            }

            if ($item->isFile() && ! copy($item->getPathname(), $path))
            {
                throw new RuntimeException("could not copy {$path}");
            }
        }
    }


    public static function removeDir(string $directory): void
    {
        if ( ! is_dir($directory))
        {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item)
        {
            if ( ! $item instanceof SplFileInfo || $item->isLink())
            {
                continue;
            }

            if ($item->isDir())
            {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($directory);
    }


    public static function temporaryDirectory(string $prefix = 'stougeiro-deploy-'): string
    {
        $directory = sys_get_temp_dir() .'/'. $prefix . bin2hex(random_bytes(6));

        if ( ! mkdir($directory, 0700, true) && ! is_dir($directory))
        {
            throw new RuntimeException("could not create {$directory}");
        }

        return $directory;
    }


    public static function temporaryArchive(string $prefix = 'stougeiro-deploy-'): string
    {
        return sys_get_temp_dir() .'/'. $prefix . bin2hex(random_bytes(6)) .'.tar.gz';
    }
}
