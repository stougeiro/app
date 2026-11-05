<?php declare(strict_types=1);

namespace Tool\Deploy;

use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use Tool\Contract\OutputInterface;


class Deployer
{
    private readonly Ssh $ssh;

    private readonly Maintenance $maintenance;

    private bool $hasRsync = false;

    private bool $localEnvExists = false;

    private bool $remoteEnvExists = false;


    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly OutputInterface $output,
    ) {
        $this->ssh = new Ssh($shell);
        $this->maintenance = new Maintenance($this->ssh);
    }


    public function check(): void
    {
        $target = $this->target;

        $this->out('check');

        foreach (['composer.json', 'composer.lock'] as $file)
        {
            if ( ! is_file($this->projectRoot .'/'. $file))
            {
                throw new RuntimeException("missing file: {$file}");
            }
        }

        $storage = $target->root .'/application/storage';
        $upload = $storage .'/upload';

        $command = 'test -d '. Ssh::quote($target->rootBase)
            .' && test -w '. Ssh::quote($target->rootBase);

        if ($target->rootDir !== null)
        {
            $command .= ' && { [ -d '. Ssh::quote($target->root) .' ] || mkdir '. Ssh::quote($target->root) .'; }';
        }

        $command .= ' && test -d '. Ssh::quote($target->root)
            .' && test -w '. Ssh::quote($target->root)
            .' && test -d '. Ssh::quote($target->webroot)
            .' && test -w '. Ssh::quote($target->webroot)
            .' && { [ ! -d '. Ssh::quote($storage) .' ] || test -w '. Ssh::quote($storage) .'; }'
            .' && { [ ! -d '. Ssh::quote($upload) .' ] || test -w '. Ssh::quote($upload) .'; }';

        $this->ssh->must($target, $command);

        $this->out('ssh ok, root and webroot are writable');

        $this->warnAboutEntrypoint();

        $this->hasRsync = $this->ssh->must(
            $target,
            'command -v rsync >/dev/null 2>&1 && echo yes || echo no',
        )->output() === 'yes';

        $this->out('transport: '. ($this->hasRsync ? 'rsync' : 'tar (fallback)'));

        $this->checkEnv();
    }


    private function checkEnv(): void
    {
        $target = $this->target;
        $local = $this->overridePath('application') .'/env.json';
        $remote = $target->root .'/application/env.json';

        $this->localEnvExists = is_file($local);
        $this->remoteEnvExists = false;

        if ( ! $this->localEnvExists)
        {
            $this->remoteEnvExists = $this->ssh->must(
                $target,
                'if [ -f '. Ssh::quote($remote) .' ]; then echo yes; else echo no; fi',
            )->output() === 'yes';
        }

        if ( ! $this->localEnvExists && ! $this->remoteEnvExists)
        {
            $this->out(
                'warning: no env found for \''. $target->name .'\'; add '. $local
                .' or create '. $remote,
            );
        }
    }


    private function assertEnvExists(): void
    {
        if ($this->localEnvExists || $this->remoteEnvExists)
        {
            return;
        }

        $local = $this->overridePath('application') .'/env.json';
        $remote = $this->target->root .'/application/env.json';

        throw new RuntimeException(
            "missing env for '{$this->target->name}': add {$local} or create {$remote}",
        );
    }


    private function warnAboutEntrypoint(): void
    {
        if (rtrim(dirname($this->target->webroot), '/') === rtrim($this->target->root, '/'))
        {
            return;
        }

        $override = $this->overridePath('public') .'/index.php';

        if ( ! is_file($override))
        {
            $this->out(
                'warning: webroot is not directly under root; add an index.php entrypoint at '
                .'.deploy/override/'. $this->target->name .'/public/index.php',
            );
        }
    }


    public function push(): void
    {
        $this->check();
        $this->assertEnvExists();

        $build = $this->buildVendor();
        $started = false;
        $snapshot = null;

        try
        {
            $this->out('enabling maintenance');
            $this->maintenance->enable($this->target);
            $started = true;

            $this->ensureRemoteDirs();

            $snapshot = $this->snapshot();
            $this->out('backup: '. $snapshot);

            $this->transfer($build['vendor']);

            $this->out('disabling maintenance');
            $this->maintenance->disable($this->target);

            $this->verify();
            $this->pruneBackups();
            $this->out('deploy complete');
        }
        catch (Throwable $error)
        {
            if ($started)
            {
                $this->out('error: '. $error->getMessage());

                if ($snapshot === null)
                {
                    $this->out('no backup: maintenance remains active');
                }
                else
                {
                    try
                    {
                        $this->maintenance->enable($this->target);
                        $this->restore($snapshot);
                        $this->maintenance->disable($this->target);
                        $this->verify();
                        $this->out('rollback complete');
                    }
                    catch (Throwable $rollback)
                    {
                        $this->out('ROLLBACK FAILED, maintenance remains active: '. $rollback->getMessage());
                    }
                }
            }

            throw $error;
        }
        finally
        {
            $this->removeDir($build['dir']);
        }
    }


    /**
     * @return list<string>
     */
    public function backups(): array
    {
        return $this->snapshots();
    }


    public function rollback(?string $timestamp): void
    {
        $this->check();

        $backups = $this->snapshots();

        if ($timestamp === null)
        {
            if ($backups === [])
            {
                throw new RuntimeException('no backup found');
            }

            $timestamp = $backups[0];
            $this->out('latest backup: '. $timestamp);
        }

        if (preg_match('/\A[0-9]{8}-[0-9]{6}\z/', $timestamp) !== 1)
        {
            throw new RuntimeException("invalid backup timestamp: {$timestamp}");
        }

        if ( ! in_array($timestamp, $backups, true))
        {
            throw new RuntimeException("backup not found: {$timestamp}");
        }

        $started = false;

        try
        {
            $this->maintenance->enable($this->target);
            $started = true;
            $this->restore($timestamp);
            $this->maintenance->disable($this->target);
            $this->verify();
            $this->out('rollback complete');
        }
        catch (Throwable $error)
        {
            if ($started)
            {
                try
                {
                    $this->maintenance->enable($this->target);
                }
                catch (Throwable)
                {
                }
            }

            throw $error;
        }
    }


    protected function out(string $message): void
    {
        $this->output->line('['. $this->target->name .'] '. $message);
    }


    /**
     * @return array{dir:string,vendor:string}
     */
    private function buildVendor(): array
    {
        $directory = sys_get_temp_dir() .'/stougeiro-deploy-'. bin2hex(random_bytes(6));

        if ( ! mkdir($directory, 0700, true) && ! is_dir($directory))
        {
            throw new RuntimeException("could not create {$directory}");
        }

        try
        {
            $this->copyFile($this->projectRoot .'/composer.json', $directory .'/composer.json');
            $this->copyFile($this->projectRoot .'/composer.lock', $directory .'/composer.lock');
            $this->copyDir($this->projectRoot .'/application', $directory .'/application', ['storage', 'env.json']);

            $this->out('build vendor: composer install --no-dev --classmap-authoritative');

            $result = $this->shell->run(
                ['composer', 'install', '--no-dev', '--classmap-authoritative', '--no-interaction', '--no-progress'],
                null,
                $directory,
            );

            if ( ! $result->ok())
            {
                $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);

                throw new RuntimeException("composer failed:\n". $detail);
            }

            $vendor = $directory .'/vendor';

            if ( ! is_dir($vendor))
            {
                throw new RuntimeException('composer did not generate the vendor directory');
            }

            return ['dir' => $directory, 'vendor' => $vendor];
        }
        catch (Throwable $error)
        {
            $this->removeDir($directory);
            throw $error;
        }
    }


    /**
     * @return list<string>
     */
    private function applicationExcludes(): array
    {
        return ['/env.json', '/storage/'];
    }


    /**
     * @return list<string>
     */
    private function publicExcludes(): array
    {
        $excludes = ['/.maintenance', ...$this->target->preserve];

        foreach ($this->target->nestedWebroots as $entry)
        {
            $excludes[] = '/'. $entry;
            $excludes[] = '/'. $entry .'/***';
        }

        return $excludes;
    }


    /**
     * @param list<string> $excludes
     * @return list<string>
     */
    private function rsyncArgs(
        string $source,
        string $destination,
        array $excludes,
        bool $dryRun,
        bool $delete = true,
    ): array {
        $target = $this->target;
        $ssh = 'ssh -p '. $target->sshPort .' -o BatchMode=yes -o ConnectTimeout=15';
        $identity = $target->identityPath();

        if ($identity !== null)
        {
            $ssh .= ' -i '. escapeshellarg($identity);
        }

        $args = [
            'rsync', '-az',
            '--checksum',
            '--delay-updates',
            '--protect-args',
            '--itemize-changes',
            '-e', $ssh,
        ];

        if ($delete)
        {
            $args[] = '--delete';
        }

        if ($dryRun)
        {
            $args[] = '--dry-run';
        }

        foreach ($excludes as $exclude)
        {
            $args[] = '--exclude='. $exclude;
        }

        $args[] = $source;
        $args[] = $target->sshTarget() .':'. $destination;

        return $args;
    }


    private function transfer(string $vendor): void
    {
        $this->ensureRemoteDirs();

        if ($this->hasRsync)
        {
            $this->transferRsync($vendor);
        }
        else
        {
            $this->transferTar($vendor);
        }

        $this->applyOverrides();
    }


    private function ensureRemoteDirs(): void
    {
        $target = $this->target;
        $directories = [
            $target->root .'/application',
            $target->root .'/application/storage/cache',
            $target->root .'/application/storage/log',
            $target->root .'/application/storage/session',
            $target->root .'/application/storage/temp',
            $target->root .'/application/storage/upload',
            $target->root .'/vendor',
            $target->root .'/.deploy',
            $target->root .'/.deploy/backups',
        ];

        $command = 'mkdir -p';
        foreach ($directories as $directory)
        {
            $command .= ' '. Ssh::quote($directory);
        }

        $this->ssh->must($target, $command);
    }


    private function transferRsync(string $vendor): void
    {
        $this->out('rsync application');
        $this->shell->mustRun(
            $this->rsyncArgs(
                $this->projectRoot .'/application/',
                $this->target->root .'/application/',
                $this->applicationExcludes(),
                false,
            ),
        );

        $this->out('rsync public');
        $this->shell->mustRun(
            $this->rsyncArgs(
                $this->projectRoot .'/public/',
                $this->target->webroot .'/',
                $this->publicExcludes(),
                false,
            ),
        );

        $this->out('rsync vendor');
        $this->shell->mustRun(
            $this->rsyncArgs($vendor .'/', $this->target->root .'/vendor/', [], false),
        );
    }


    private function transferTar(string $vendor): void
    {
        $target = $this->target;
        $rootArchive = $this->temporaryArchive();
        $webrootArchive = $this->temporaryArchive();
        $rootRemote = $target->root .'/.deploy/incoming-root-'. bin2hex(random_bytes(4)) .'.tar.gz';
        $webrootRemote = $target->root .'/.deploy/incoming-webroot-'. bin2hex(random_bytes(4)) .'.tar.gz';

        try
        {
            $this->shell->mustRun([
                'tar', '-czf', $rootArchive,
                '--exclude=application/env.json',
                '--exclude=application/storage',
                '-C', $this->projectRoot,
                'application',
                '-C', dirname($vendor),
                'vendor',
            ]);

            $webrootCommand = ['tar', '-czf', $webrootArchive, '--exclude=.maintenance'];

            foreach ($target->nestedWebroots as $entry)
            {
                $webrootCommand[] = '--exclude=./'. $entry;
            }

            array_push($webrootCommand, '-C', $this->projectRoot .'/public', '.');

            $this->shell->mustRun($webrootCommand);

            $this->out('tar fallback: uploading');
            $this->upload($rootArchive, $rootRemote);
            $this->upload($webrootArchive, $webrootRemote);

            $this->ssh->must(
                $target,
                'tar -xzf '. Ssh::quote($rootRemote) .' -C '. Ssh::quote($target->root)
                .' && rm -f '. Ssh::quote($rootRemote)
                .' && tar -xzf '. Ssh::quote($webrootRemote) .' -C '. Ssh::quote($target->webroot)
                .' && rm -f '. Ssh::quote($webrootRemote),
            );
        }
        finally
        {
            if (is_file($rootArchive))
            {
                @unlink($rootArchive);
            }

            if (is_file($webrootArchive))
            {
                @unlink($webrootArchive);
            }
        }
    }


    private function applyOverrides(): void
    {
        foreach ([
            ['application', $this->overridePath('application'), $this->target->root .'/application'],
            ['public', $this->overridePath('public'), $this->target->webroot],
        ] as [$label, $source, $destination])
        {
            if ( ! is_dir($source))
            {
                continue;
            }

            $this->out('override '. $label);

            if ($this->hasRsync)
            {
                $this->shell->mustRun(
                    $this->rsyncArgs($source .'/', $destination .'/', [], false, false),
                );
                continue;
            }

            $this->transferTarDirectory($source, $destination);
        }
    }


    private function overridePath(string $tree): string
    {
        return $this->projectRoot .'/.deploy/override/'. $this->target->name .'/'. $tree;
    }


    private function transferTarDirectory(string $source, string $destination): void
    {
        $archive = $this->temporaryArchive();
        $remote = $this->target->root .'/.deploy/incoming-'. bin2hex(random_bytes(4)) .'.tar.gz';

        try
        {
            $this->shell->mustRun(['tar', '-czf', $archive, '-C', $source, '.']);
            $this->upload($archive, $remote);

            $this->ssh->must(
                $this->target,
                'tar -xzf '. Ssh::quote($remote) .' -C '. Ssh::quote($destination)
                .' && rm -f '. Ssh::quote($remote),
            );
        }
        finally
        {
            if (is_file($archive))
            {
                @unlink($archive);
            }
        }
    }


    private function upload(string $local, string $remote): void
    {
        $target = $this->target;
        $scp = [
            'scp',
            '-P', (string) $target->sshPort,
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=15',
        ];

        $identity = $target->identityPath();

        if ($identity !== null)
        {
            $scp[] = '-i';
            $scp[] = $identity;
        }

        $scp[] = $local;
        $scp[] = $target->sshTarget() .':'. $remote;

        $this->shell->mustRun($scp);
    }


    private function temporaryArchive(): string
    {
        return sys_get_temp_dir() .'/stougeiro-deploy-'. bin2hex(random_bytes(6)) .'.tar.gz';
    }


    private function verify(): void
    {
        $target = $this->target;
        $root = $this->httpStatus($target->url);

        if ($root !== '200')
        {
            throw new RuntimeException('verify: GET '. $target->url .' returned '. $root);
        }

        $body = $this->shell->mustRun(
            ['curl', '-sS', '-L', '--max-time', '20', $target->url],
        )->stdout;

        if (preg_match('/Fatal error|Uncaught|Fallback::emit/i', $body) === 1)
        {
            throw new RuntimeException('verify: response contains a fatal error');
        }

        $missing = rtrim($target->url, '/') .'/missing';

        if ($this->httpStatus($missing) !== '404')
        {
            throw new RuntimeException('verify: GET '. $missing .' did not return 404');
        }

        $this->out('verify: 200 at / and 404 at /missing');
    }


    private function httpStatus(string $url): string
    {
        $result = $this->shell->run(
            ['curl', '-sS', '-o', '/dev/null', '-w', '%{http_code}', '-L', '--max-time', '20', $url],
        );

        return $result->ok() ? $result->output() : '000';
    }


    private function snapshot(): string
    {
        $target = $this->target;
        $name = gmdate('Ymd-His');
        $directory = $this->backupDirectory() .'/'. $name;

        $webroot = 'tar -czf '. Ssh::quote($directory .'/webroot.tar.gz');

        foreach ($target->nestedWebroots as $entry)
        {
            $webroot .= ' --exclude='. Ssh::quote('./'. $entry);
        }

        $webroot .= ' -C '. Ssh::quote($target->webroot) .' .';

        $this->ssh->must(
            $target,
            'mkdir -p '. Ssh::quote($directory)
            .' && tar -czf '. Ssh::quote($directory .'/root.tar.gz')
            .' -C '. Ssh::quote($target->root)
            .' application vendor'
            .' && '. $webroot,
        );

        $manifest = json_encode(
            [
                'target' => $target->name,
                'root' => $target->root,
                'webroot' => $target->webroot,
                'nestedWebroots' => $target->nestedWebroots,
                'createdAt' => gmdate('c'),
            ],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ) ."\n";

        $this->ssh->must(
            $target,
            'cat > '. Ssh::quote($directory .'/manifest.json'),
            $manifest,
        );

        return $name;
    }


    private function backupDirectory(): string
    {
        return $this->target->root .'/.deploy/backups/'. $this->target->name;
    }


    private function pruneBackups(): void
    {
        $target = $this->target;

        $this->ssh->run(
            $target,
            'ls -1dt '. Ssh::quote($this->backupDirectory()) .'/*/ 2>/dev/null'
            .' | tail -n +'. ($target->backupKeep + 1)
            .' | xargs -r rm -rf',
        );
    }


    /**
     * @return list<string>
     */
    private function snapshots(): array
    {
        $output = $this->ssh->must(
            $this->target,
            'ls -1dt '. Ssh::quote($this->backupDirectory()) .'/*/ 2>/dev/null || true',
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


    private function restore(string $timestamp): void
    {
        $target = $this->target;
        $directory = $this->backupDirectory() .'/'. $timestamp;

        $this->assertSnapshotLayout($directory);

        $delete = 'find '. Ssh::quote($target->webroot) .' -mindepth 1 -maxdepth 1';

        foreach ($target->nestedWebroots as $entry)
        {
            $delete .= ' ! -name '. Ssh::quote($entry);
        }

        $delete .= ' -exec rm -rf {} +';

        $this->ssh->must(
            $target,
            'rm -rf '. Ssh::quote($target->root .'/application')
            .' '. Ssh::quote($target->root .'/vendor')
            .' && '. $delete
            .' && tar -xzf '. Ssh::quote($directory .'/root.tar.gz')
            .' -C '. Ssh::quote($target->root)
            .' && tar -xzf '. Ssh::quote($directory .'/webroot.tar.gz')
            .' -C '. Ssh::quote($target->webroot),
        );
    }


    private function assertSnapshotLayout(string $directory): void
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
    }


    private function copyFile(string $source, string $destination): void
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
    private function copyDir(string $source, string $destination, array $excludeTop = []): void
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


    private function removeDir(string $directory): void
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
}
