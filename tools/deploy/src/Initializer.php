<?php declare(strict_types=1);

namespace Deploy;

use RuntimeException;


class Initializer
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }


    public function run(): void
    {
        $base = rtrim($this->projectRoot, '/') .'/.deploy';
        $config = $this->projectRoot .'/deploy.json';
        $example = $this->projectRoot .'/tools/deploy/deploy.example.json';

        if (is_link($config))
        {
            throw new RuntimeException("deploy.json cannot be a symlink: {$config}");
        }

        $configExists = file_exists($config);

        if ($configExists && ! is_file($config))
        {
            throw new RuntimeException("deploy.json is not a regular file: {$config}");
        }

        if ( ! $configExists && ! is_file($example))
        {
            throw new RuntimeException("deploy example not found: {$example}");
        }

        $this->ensureDirectory($base);
        $this->ensureDirectory($base .'/ssh');

        foreach (Config::ENVIRONMENTS as $environment)
        {
            foreach (['application', 'public'] as $tree)
            {
                $this->ensureDirectory($base .'/override/'. $environment .'/'. $tree);
            }
        }

        if ($configExists)
        {
            $this->out('deploy.json already exists');
        }
        else
        {
            if ( ! copy($example, $config))
            {
                throw new RuntimeException("could not create deploy.json from {$example}");
            }

            @chmod($config, 0600);
            $this->out('created deploy.json from deploy.example.json');
        }

        $this->out('');
        $this->out('next:');
        $this->out('  edit deploy.json');
        $this->out('  place SSH keys in .deploy/ssh/ or ~/.ssh/');
        $this->out('  add per-target overrides in .deploy/override/<target>/');
        $this->out('  run: deploy check --target=staging');
    }


    private function ensureDirectory(string $path): void
    {
        if (is_link($path))
        {
            throw new RuntimeException("refusing to use symlink: {$path}");
        }

        if (is_dir($path))
        {
            $this->out('exists '. $this->display($path));

            return;
        }

        if (file_exists($path))
        {
            throw new RuntimeException("not a directory: {$path}");
        }

        if ( ! mkdir($path, 0700, true) && ! is_dir($path))
        {
            throw new RuntimeException("could not create directory: {$path}");
        }

        @chmod($path, 0700);
        $this->out('created '. $this->display($path));
    }


    private function display(string $path): string
    {
        $root = rtrim($this->projectRoot, '/');

        if (str_starts_with($path, $root .'/'))
        {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }


    private function out(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }
}
