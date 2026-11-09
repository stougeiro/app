<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code;

use RuntimeException;
use Sourcerer\Tool\Support\FileSystem;


class Initializer
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ?string $examplePath = null,
    ) {
    }


    /** @return list<string> */
    public function run(): array
    {
        $base = rtrim($this->projectRoot, '/') .'/.sourcerer/code';
        $config = $base .'/config.json';
        $example = $this->examplePath ?? dirname(__DIR__) .'/code.example.json';

        if (is_link($base)) {
            throw new RuntimeException("refusing to use symlink: {$base}");
        }

        $lines = [];
        $directories = [
            $base,
            $base .'/ssh',
            $base .'/stub',
            $base .'/releases',
            $base .'/events',
        ];

        foreach (Config::TARGETS as $environment) {
            foreach (['application', 'public'] as $tree) {
                $directories[] = $base .'/override/'. $environment .'/'. $tree;
            }
        }

        foreach ($directories as $directory) {
            $lines[] = $this->directory($directory);
        }

        $lines[] = $this->file($base .'/stub/index.php', dirname(__DIR__) .'/resources/stub/index.php');
        $lines[] = $this->file($base .'/stub/.htaccess', dirname(__DIR__) .'/resources/stub/.htaccess');
        $lines[] = $this->configuration($config, $example);

        return $lines;
    }


    private function directory(string $path): string
    {
        if (is_link($path)) {
            throw new RuntimeException("refusing to use symlink: {$path}");
        }

        if (is_dir($path)) {
            return 'exists '. $this->display($path);
        }

        FileSystem::ensureDirectory($path);

        return 'created '. $this->display($path);
    }


    private function file(string $path, string $template): string
    {
        if (is_link($path)) {
            throw new RuntimeException("refusing to use symlink: {$path}");
        }

        if (is_file($path)) {
            return 'exists '. $this->display($path);
        }

        FileSystem::copyFile($template, $path);
        FileSystem::chmod($path, 0600);

        return 'created '. $this->display($path);
    }


    private function configuration(string $config, string $example): string
    {
        if (is_link($config)) {
            throw new RuntimeException("refusing to use symlink: {$config}");
        }

        if (is_file($config)) {
            return 'exists '. $this->display($config);
        }

        FileSystem::copyFile($example, $config);
        FileSystem::chmod($config, 0600);

        return 'created '. $this->display($config);
    }


    private function display(string $path): string
    {
        $root = rtrim($this->projectRoot, '/');

        if (str_starts_with($path, $root .'/')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }
}
