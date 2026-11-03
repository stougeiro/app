<?php declare(strict_types=1);

namespace Deploy;

use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;


class PlanGate
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }


    public function sourceHash(Target $target): string
    {
        $files = [];

        $this->collect($this->projectRoot .'/application', 'application', $files, true);
        $this->collect($this->projectRoot .'/public_html', 'public_html', $files, false);

        $overrides = $this->projectRoot .'/.deploy/override/'. $target->name;

        if (is_dir($overrides))
        {
            $this->collect($overrides, 'override/'. $target->name, $files, false);
        }

        foreach (['composer.json', 'composer.lock'] as $name)
        {
            $path = $this->projectRoot .'/'. $name;
            $hash = hash_file('sha256', $path);

            if ($hash === false)
            {
                throw new RuntimeException("could not read {$path}");
            }

            $files[$name] = $hash;
        }

        ksort($files);

        $context = hash_init('sha256');

        foreach ($files as $path => $hash)
        {
            hash_update($context, $path ."\0". $hash ."\n");
        }

        $targetConfig = json_encode(
            $target->fingerprintData(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        hash_update($context, "target\0". $targetConfig ."\n");

        return hash_final($context);
    }


    public function write(Target $target, string $sourceHash, bool $withEnv, ?string $envHash): void
    {
        $directory = $this->projectRoot .'/.deploy/plans';

        if ( ! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory))
        {
            throw new RuntimeException("could not create {$directory}");
        }

        $payload = json_encode(
            [
                'target' => $target->name,
                'sourceHash' => $sourceHash,
                'withEnv' => $withEnv,
                'envHash' => $envHash,
                'createdAt' => gmdate('c'),
            ],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ) ."\n";

        $temporary = tempnam($directory, 'plan');

        if ($temporary === false)
        {
            throw new RuntimeException('could not create temporary plan');
        }

        if (file_put_contents($temporary, $payload) === false)
        {
            @unlink($temporary);
            throw new RuntimeException('could not write temporary plan');
        }

        chmod($temporary, 0600);

        if ( ! rename($temporary, $this->file($target)))
        {
            @unlink($temporary);
            throw new RuntimeException('could not write the plan');
        }
    }


    /**
     * @return array{target:string,sourceHash:string,withEnv:bool,envHash:?string,createdAt:string}|null
     */
    public function read(Target $target): ?array
    {
        $file = $this->file($target);

        if ( ! is_file($file))
        {
            return null;
        }

        $raw = file_get_contents($file);

        if ($raw === false)
        {
            throw new RuntimeException("could not read {$file}");
        }

        try
        {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        catch (JsonException $error)
        {
            throw new RuntimeException('invalid plan: '. $error->getMessage(), 0, $error);
        }

        if ( ! is_array($data))
        {
            throw new RuntimeException('invalid plan: expected object');
        }

        $name = $data['target'] ?? null;
        $source = $data['sourceHash'] ?? null;
        $withEnv = $data['withEnv'] ?? null;
        $envHash = $data['envHash'] ?? null;
        $createdAt = $data['createdAt'] ?? null;

        if (
            ! is_string($name)
            || ! is_string($source)
            || ! is_bool($withEnv)
            || ! (is_string($envHash) || $envHash === null)
            || ! is_string($createdAt)
        ) {
            throw new RuntimeException('invalid plan: missing or incorrect fields');
        }

        return [
            'target' => $name,
            'sourceHash' => $source,
            'withEnv' => $withEnv,
            'envHash' => $envHash,
            'createdAt' => $createdAt,
        ];
    }


    public function consume(Target $target): void
    {
        $file = $this->file($target);

        if (is_file($file) && ! unlink($file))
        {
            throw new RuntimeException("could not remove plan {$file}");
        }
    }


    public function assertPushAllowed(Target $target, string $sourceHash, bool $withEnv, ?string $envHash): void
    {
        if ( ! $target->requiresPlan)
        {
            return;
        }

        $plan = $this->read($target);

        if ($plan === null)
        {
            $suffix = $withEnv ? ' --with-env' : '';

            throw new RuntimeException(
                "missing plan for '{$target->name}'. run: deploy plan --target={$target->name}{$suffix}",
            );
        }

        if ($plan['target'] !== $target->name)
        {
            throw new RuntimeException('plan belongs to another target');
        }

        if ($plan['withEnv'] !== $withEnv)
        {
            if ($withEnv)
            {
                throw new RuntimeException(
                    "plan found without --with-env. run: deploy plan --target={$target->name} --with-env",
                );
            }

            throw new RuntimeException(
                "plan created with --with-env. use: deploy push --target={$target->name} --with-env"
                ." or: deploy plan --target={$target->name}",
            );
        }

        if ( ! hash_equals($plan['sourceHash'], $sourceHash))
        {
            $suffix = $withEnv ? ' --with-env' : '';

            throw new RuntimeException(
                "files changed since the plan. run again: deploy plan --target={$target->name}{$suffix}",
            );
        }

        if ( ! $withEnv)
        {
            return;
        }

        if ($envHash === null || $plan['envHash'] === null || ! hash_equals($plan['envHash'], $envHash))
        {
            throw new RuntimeException(
                "env changed since the plan. run again: deploy plan --target={$target->name} --with-env",
            );
        }
    }


    private function file(Target $target): string
    {
        return $this->projectRoot .'/.deploy/plans/'. $target->name .'.json';
    }


    /**
     * @param array<string, string> $files
     */
    private function collect(string $directory, string $prefix, array &$files, bool $application): void
    {
        if ( ! is_dir($directory))
        {
            throw new RuntimeException("missing directory: {$directory}");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file)
        {
            if ( ! $file instanceof SplFileInfo || $file->isLink() || ! $file->isFile())
            {
                continue;
            }

            $relative = str_replace('\\', '/', $prefix .'/'. substr(
                $file->getPathname(),
                strlen($directory) + 1,
            ));

            if ($application && (
                $relative === 'application/env.json'
                || str_starts_with($relative, 'application/storage/')
            )) {
                continue;
            }

            $hash = hash_file('sha256', $file->getPathname());

            if ($hash === false)
            {
                throw new RuntimeException('could not read '. $file->getPathname());
            }

            $files[$relative] = $hash;
        }
    }
}
