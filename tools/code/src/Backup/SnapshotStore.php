<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Backup;

use JsonException;
use RuntimeException;
use Sourcerer\Tool\Code\Ssh;
use Sourcerer\Tool\Code\Target;


final class SnapshotStore
{
    public function __construct(
        private readonly Target $target,
        private readonly Ssh $ssh,
    ) {
    }


    /**
     * @return list<string>
     */
    public function backups(): array
    {
        return $this->snapshots();
    }


    /** @param array<string, mixed> $release */
    public function create(array $release = [], ?string $previousReleaseId = null): string
    {
        $target = $this->target;
        $name = gmdate('Ymd-His');
        $directory = $this->directory() .'/'. $name;

        $webroot = 'tar -czf '. Ssh::quote($directory .'/webroot.tar.gz');

        foreach ($target->nestedWebroots as $entry)
        {
            $webroot .= ' --exclude='. Ssh::quote('./'. $entry);
        }

        $webroot .= ' -C '. Ssh::quote($target->webroot) .' .';

        $storage = 'if [ -d '. Ssh::quote($target->root .'/application/storage') .' ]; '
            .'then tar -czf '. Ssh::quote($directory .'/storage.tar.gz')
            .' -C '. Ssh::quote($target->root) .' application/storage; fi';

        $this->ssh->must(
            $target,
            'mkdir -p '. Ssh::quote($directory)
            .' && tar -czf '. Ssh::quote($directory .'/root.tar.gz')
            .' --exclude=application/storage'
            .' --exclude=application/env.json'
            .' -C '. Ssh::quote($target->root)
            .' application vendor'
            .' && '. $webroot
            .' && '. $storage,
        );

        $manifest = json_encode(
            array_merge($release, [
                'target' => $target->name,
                'root' => $target->root,
                'webroot' => $target->webroot,
                'nestedWebroots' => $target->nestedWebroots,
                'createdAt' => gmdate('c'),
                'previousReleaseId' => $previousReleaseId,
            ]),
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ) ."\n";

        $this->ssh->must(
            $target,
            'cat > '. Ssh::quote($directory .'/manifest.json'),
            $manifest,
        );

        return $name;
    }


    /** @return array<string, mixed> */
    public function restore(string $timestamp): array
    {
        $target = $this->target;
        $directory = $this->directory() .'/'. $timestamp;

        $manifest = $this->assertSnapshotLayout($directory);

        $delete = 'find '. Ssh::quote($target->webroot) .' -mindepth 1 -maxdepth 1';

        foreach ($target->nestedWebroots as $entry)
        {
            $delete .= ' ! -name '. Ssh::quote($entry);
        }

        $delete .= ' -exec rm -rf {} +';

        $storage = 'if [ -f '. Ssh::quote($directory .'/storage.tar.gz') .' ]; '
            .'then rm -rf '. Ssh::quote($target->root .'/application/storage')
            .' && tar -xzf '. Ssh::quote($directory .'/storage.tar.gz')
            .' -C '. Ssh::quote($target->root) .'; fi';

        $this->ssh->must(
            $target,
            'rm -rf '. Ssh::quote($target->root .'/application')
            .' '. Ssh::quote($target->root .'/vendor')
            .' && '. $delete
            .' && tar -xzf '. Ssh::quote($directory .'/root.tar.gz')
            .' -C '. Ssh::quote($target->root)
            . ' && tar -xzf '. Ssh::quote($directory .'/webroot.tar.gz')
            .' -C '. Ssh::quote($target->webroot)
            .' && rm -f '. Ssh::quote($target->root .'/application/env.json')
            .' && '. $storage,
        );

        return $manifest;
    }


    /** @return list<string> */
    public function snapshots(): array
    {
        $output = $this->ssh->must(
            $this->target,
            'ls -1dt '. Ssh::quote($this->directory()) .'/*/ 2>/dev/null || true',
        )->stdout;

        $snapshots = [];

        foreach (explode("\n", $output) as $line)
        {
            $name = basename(rtrim(trim($line), '/'));

            if (preg_match('/\A[0-9]{8}-[0-9]{6}\z/', $name) === 1)
            {
                $snapshots[] = $name;
            }
        }

        return $snapshots;
    }


    public function pruneBackups(): void
    {
        $target = $this->target;

        $this->ssh->run(
            $target,
            'ls -1dt '. Ssh::quote($this->directory()) .'/*/ 2>/dev/null'
            .' | tail -n +'. ($target->backupKeep + 1)
            .' | xargs -r rm -rf',
        );
    }


    public function pruneReleases(string $currentReleaseId): void
    {
        $directory = $this->target->root .'/.deploy/releases';
        $result = $this->ssh->run($this->target, 'ls -1dt '. Ssh::quote($directory) .'/*/ 2>/dev/null || true');
        $releases = [];

        foreach (explode("\n", trim($result->stdout)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $releases[] = basename(rtrim(trim($line), '/'));
        }

        $keep = max(1, $this->target->releaseKeep);
        $kept = [];

        foreach ($releases as $releaseId) {
            if ($releaseId === $currentReleaseId || count($kept) < $keep) {
                $kept[] = $releaseId;
            }
        }

        $remove = array_diff($releases, $kept);

        foreach ($remove as $releaseId) {
            $this->ssh->run(
                $this->target,
                'rm -rf '. Ssh::quote($directory .'/'. $releaseId),
            );
        }
    }


    private function directory(): string
    {
        return $this->target->root .'/.deploy/backups';
    }


    /** @return array<string, mixed> */
    private function assertSnapshotLayout(string $directory): array
    {
        $result = $this->ssh->must(
            $this->target,
            'if [ -f '. Ssh::quote($directory .'/manifest.json') .' ]; '
            .'then cat '. Ssh::quote($directory .'/manifest.json') .'; else printf __MISSING__; fi',
        );

        if (trim($result->stdout) === '__MISSING__')
        {
            throw new RuntimeException('snapshot manifest not found');
        }

        try
        {
            $manifest = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        }
        catch (JsonException $error)
        {
            throw new RuntimeException('invalid snapshot manifest: '. $error->getMessage(), 0, $error);
        }

        if ( ! is_array($manifest))
        {
            throw new RuntimeException('invalid snapshot manifest: expected object');
        }

        if (($manifest['root'] ?? null) !== $this->target->root
            || ($manifest['webroot'] ?? null) !== $this->target->webroot)
        {
            throw new RuntimeException('snapshot layout does not match the current target');
        }

        /** @var array<string, mixed> $manifest */
        return $manifest;
    }
}
