<?php declare(strict_types=1);

namespace Tool\Deploy\Release;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;


final class ReleaseHasher
{
    /** @var list<string> */
    private const EXCLUDED = [
        'application/storage/',
        'application/env.json',
        'public/.maintenance',
    ];


    public function hash(string $projectRoot): string
    {
        $files = [];

        foreach (['application', 'public'] as $directory) {
            $this->collect($projectRoot, $directory, $files);
        }

        foreach (['composer.json', 'composer.lock'] as $file) {
            $path = $projectRoot .'/'. $file;

            if ( ! is_file($path)) {
                throw new RuntimeException("missing file: {$file}");
            }

            $files[$file] = $path;
        }

        ksort($files, SORT_STRING);

        $manifest = '';

        foreach ($files as $relative => $path) {
            $hash = hash_file('sha256', $path);

            if ($hash === false) {
                throw new RuntimeException("could not hash file: {$relative}");
            }

            $manifest .= $relative ."\0". $hash ."\n";
        }

        return 'sha256:'. hash('sha256', $manifest);
    }


    /** @param array<string, string> $files */
    private function collect(string $projectRoot, string $directory, array &$files): void
    {
        $root = $projectRoot .'/'. $directory;

        if ( ! is_dir($root)) {
            throw new RuntimeException("missing directory: {$directory}");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ( ! $item instanceof SplFileInfo || $item->isLink() || ! $item->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($projectRoot) + 1));

            if ($this->excluded($relative)) {
                continue;
            }

            $files[$relative] = $item->getPathname();
        }
    }

    private function excluded(string $relative): bool
    {
        foreach (self::EXCLUDED as $excluded) {
            if (str_starts_with($relative, $excluded)) {
                return true;
            }
        }

        return false;
    }
}
