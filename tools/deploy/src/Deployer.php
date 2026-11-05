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
use Tool\Deploy\Release\ArtifactVerifier;
use Tool\Deploy\Release\ReleaseHasher;
use Tool\Deploy\Release\ReleaseId;
use Tool\Deploy\Release\ReleaseManifest;
use Tool\Deploy\Event\DeployEvent;
use Tool\Deploy\Event\EventLog;
use Tool\Deploy\Lock\TargetLock;
use Tool\Deploy\Operator\Operator;
use Tool\Deploy\Release\ReleaseStore;
use Tool\Deploy\Requirements\ComposerRequirements;
use Tool\Deploy\Requirements\WebProbe;
use Tool\Deploy\Retirement\RedirectStub;


class Deployer
{
    private readonly Ssh $ssh;

    private readonly Maintenance $maintenance;

    private bool $hasRsync = false;

    private bool $localEnvExists = false;



    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly OutputInterface $output,
        private readonly Operator $operator,
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
        $this->checkRequirements();
    }


    private function checkEnv(): void
    {
        $local = $this->overridePath('application') .'/env.json';

        $this->localEnvExists = is_file($local);

        if ( ! $this->localEnvExists) {
            $this->out(
                "warning: no env override for '{$this->target->name}'; add {$local}",
            );
        }
    }


    private function assertEnvExists(): void
    {
        if ($this->localEnvExists) {
            return;
        }

        $local = $this->overridePath('application') .'/env.json';

        throw new RuntimeException(
            "missing env for '{$this->target->name}': add {$local}",
        );
    }



    private function checkRequirements(): void
    {
        $current = $this->readCurrent();

        if (($current['status'] ?? null) === 'redirected') {
            $this->out('requirements: skipped for redirected target');

            return;
        }

        $requirements = ComposerRequirements::fromFile($this->projectRoot .'/composer.json');
        $probe = (new WebProbe())->inspect($this->target, $this->shell);
        $requirements->assert($probe);

        $this->out('requirements ok: PHP '. $probe['php']);
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


    public function push(bool $forceLock = false): void
    {
        $lock = new TargetLock($this->target, $this->shell, $this->operator, 'push');
        $previousOwner = $lock->acquire($forceLock);

        if ($previousOwner !== null) {
            $lockReleaseId = $this->currentReleaseId() ?? 'lock';
            $this->writeEvent('lock.override', $lockReleaseId, null, $this->target->name, null, [
                'forcedLock' => true,
                'previousOwner' => $previousOwner,
            ]);
        }

        $build = null;
        $releaseArchive = null;
        $started = false;
        $snapshot = null;

        try
        {
            $this->check();
            $this->assertEnvExists();

            $contentHash = (new ReleaseHasher())->hash($this->projectRoot);
            $releaseId = ReleaseId::fromHash($contentHash);
            $version = $this->releaseVersion();
            $previousReleaseId = $this->currentReleaseId();

            $build = $this->buildVendor();
            $releaseArchive = $this->createReleaseArchive($build['vendor']);
            $artifactHash = (new ArtifactVerifier())->hash($releaseArchive);

            $manifest = new ReleaseManifest(
                $releaseId,
                $contentHash,
                $version['version'],
                $version['source'],
                gmdate('c'),
                $version['git'],
                $previousReleaseId,
                $this->operator->toArray(),
                $artifactHash,
            );

            $store = new ReleaseStore($this->projectRoot);

            $this->out('enabling maintenance');
            $this->maintenance->enable($this->target);
            $started = true;

            $this->ensureRemoteDirs();

            $snapshot = $this->snapshot($manifest->toArray(), $previousReleaseId);
            $this->out('backup: '. $snapshot);

            $this->transfer($build['vendor']);

            $this->out('disabling maintenance');
            $this->maintenance->disable($this->target);

            $this->verify();

            $store->persist($manifest);
            $artifactVerified = $this->uploadRelease($manifest, $releaseArchive);
            $this->writeCurrent($manifest, $snapshot, 'current');
            $this->writeLocalTarget([
                'releaseId' => $manifest->releaseId,
                'contentHash' => $manifest->contentHash,
                'version' => $manifest->version,
                'status' => 'current',
                'backup' => $snapshot,
            ]);
            $this->writeEvent('push', $manifest->releaseId, null, $this->target->name, $snapshot, [
                'artifactVerified' => $artifactVerified,
            ]);

            $this->pruneBackups();
            $this->pruneReleases($manifest->releaseId);
            $this->pruneEvents();
            $this->out('release: '. $manifest->releaseId .' ('. $manifest->version .')');
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
                        $this->applyOverrides();
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
            $lock->release();

            if ($releaseArchive !== null && is_file($releaseArchive)) {
                @unlink($releaseArchive);
            }

            if ($build !== null) {
                $this->removeDir($build['dir']);
            }
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
        $this->assertEnvExists();

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
            $restored = $this->restore($timestamp);
            $previousReleaseId = $restored['previousReleaseId'] ?? null;

            if (is_string($previousReleaseId) && $previousReleaseId !== '') {
                $this->writeCurrentReleaseId($previousReleaseId);
                $this->writeLocalTarget(['releaseId' => $previousReleaseId, 'status' => 'current']);
            } else {
                $this->deleteCurrent();
                $this->writeLocalTarget(['status' => 'maintenance']);
            }

            $this->applyOverrides();
            $this->maintenance->disable($this->target);
            $this->verify();
            $rollbackRelease = $restored['previousReleaseId'] ?? $restored['releaseId'] ?? 'unknown';
            $this->writeEvent('rollback', is_string($rollbackRelease) ? $rollbackRelease : 'unknown', null, null, $timestamp);
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
            $target->root .'/.deploy/releases',
            $target->root .'/.deploy/events',
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


    /** @param array<string, mixed> $release */
    private function snapshot(array $release = [], ?string $previousReleaseId = null): string
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


    private function backupDirectory(): string
    {
        return $this->target->root .'/.deploy/backups';
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

    private function pruneReleases(string $currentReleaseId): void
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


    private function pruneEvents(): void
    {
        $directory = $this->target->root .'/.deploy/events';
        $keep = max(1, $this->target->eventKeep);

        $this->ssh->run(
            $this->target,
            'ls -1t '. Ssh::quote($directory) .'/*.json 2>/dev/null'
            .' | tail -n +'. ($keep + 1)
            .' | xargs -r rm -f',
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


    /** @return array<string, mixed> */
    private function restore(string $timestamp): array
    {
        $target = $this->target;
        $directory = $this->backupDirectory() .'/'. $timestamp;

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


    /** @return array{version: string, source: string, git: null|array{commit: string, branch: string, dirty: bool}} */
    private function releaseVersion(): array
    {
        $versionFile = $this->projectRoot .'/VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
        $source = 'version';

        if ($version === '') {
            $env = getenv('DEPLOY_VERSION');
            $version = is_string($env) ? trim($env) : '';
            $source = 'env';
        }

        if ($version === '') {
            $version = '0.0.0';
            $source = 'none';
        }

        return ['version' => $version, 'source' => $source, 'git' => $this->gitInfo()];
    }


    /** @return null|array{commit: string, branch: string, dirty: bool} */
    private function gitInfo(): ?array
    {
        $commit = $this->shell->run(['git', '-C', $this->projectRoot, 'rev-parse', '--short', 'HEAD']);

        if ( ! $commit->ok() || trim($commit->stdout) === '') {
            return null;
        }

        $branch = $this->shell->run(['git', '-C', $this->projectRoot, 'rev-parse', '--abbrev-ref', 'HEAD']);
        $status = $this->shell->run(['git', '-C', $this->projectRoot, 'status', '--porcelain']);

        return [
            'commit' => trim($commit->stdout),
            'branch' => $branch->ok() ? trim($branch->stdout) : '',
            'dirty' => $status->ok() && trim($status->stdout) !== '',
        ];
    }


    private function createReleaseArchive(string $vendor): string
    {
        $archive = $this->temporaryArchive();

        $this->shell->mustRun([
            'tar', '-czf', $archive,
            '--exclude=application/storage',
            '--exclude=application/env.json',
            '--exclude=public/.maintenance',
            '-C', $this->projectRoot,
            'application', 'public', 'composer.json', 'composer.lock',
            '-C', dirname($vendor),
            'vendor',
        ]);

        if ( ! is_file($archive)) {
            throw new RuntimeException('release archive was not created');
        }

        return $archive;
    }


        private function uploadRelease(ReleaseManifest $manifest, string $archive): ?bool
    {
        $target = $this->target;
        $directory = $target->root .'/.deploy/releases/'. $manifest->releaseId;
        $remoteArchive = $directory .'/release.tar.gz';

        $this->ssh->must($target, 'mkdir -p '. Ssh::quote($directory));
        $this->upload($archive, $remoteArchive);

        $verified = null;

        if ($manifest->artifactHash === null) {
            $this->out('warning: release has no artifact hash; integrity not verified');
        } else {
            $verification = (new ArtifactVerifier())->verifyRemote(
                $target,
                $this->shell,
                $remoteArchive,
                $manifest->artifactHash,
            );

            if ( ! $verification['available']) {
                $this->out('warning: remote artifact hash tool not found; integrity not verified');
            } elseif ( ! $verification['ok']) {
                throw new RuntimeException("artifact hash mismatch: {$manifest->releaseId}");
            } else {
                $verified = true;
            }
        }

        try {
            $json = json_encode(
                $manifest->toArray(),
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ) ."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('could not encode release manifest: '. $exception->getMessage(), 0, $exception);
        }

        $this->writeRemoteFile($directory .'/manifest.json', $json);

        return $verified;
    }



    private function writeCurrent(ReleaseManifest $manifest, string $snapshot, string $status, ?string $previousReleaseId = null, ?string $promotedFrom = null): void
    {
        $data = [
            'releaseId' => $manifest->releaseId,
            'contentHash' => $manifest->contentHash,
            'version' => $manifest->version,
            'status' => $status,
            'deployedAt' => gmdate('c'),
            'previousReleaseId' => $previousReleaseId ?? $manifest->previousReleaseId,
            'backup' => $snapshot,
        ];

        if ($promotedFrom !== null) {
            $data['promotedFrom'] = $promotedFrom;
        }

        $this->writeRemoteJson($this->target->root .'/.deploy/current.json', $data);
    }


    /** @param array<string, mixed> $context */
    private function writeEvent(
        string $action,
        string $releaseId,
        ?string $from = null,
        ?string $to = null,
        ?string $backup = null,
        array $context = [],
    ): void {
        try {
            (new EventLog($this->projectRoot, $this->target, $this->shell))->write(new DeployEvent(
                $action,
                $releaseId,
                $this->target->name,
                $from,
                $to,
                gmdate('c'),
                $this->operator->toArray(),
                $backup,
                context: $context,
            ));
        } catch (Throwable $exception) {
            $this->out('warning: could not write deploy event: '. $exception->getMessage());
        }
    }


    /** @param array<string, mixed> $data */
    private function writeLocalTarget(array $data): void
    {
        $directory = $this->projectRoot .'/.deploy/targets';

        if ( ! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("could not create local target directory: {$directory}");
        }

        try {
            $json = json_encode(
                ['target' => $this->target->name, 'updatedAt' => gmdate('c')] + $data,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('could not encode local target metadata: '. $exception->getMessage(), 0, $exception);
        }

        if (file_put_contents($directory .'/'. $this->target->name .'.json', $json ."\n") === false) {
            throw new RuntimeException('could not write local target metadata');
        }
    }


    private function writeCurrentReleaseId(string $releaseId): void
    {
        $this->writeRemoteJson($this->target->root .'/.deploy/current.json', [
            'releaseId' => $releaseId,
            'status' => 'current',
        ]);
    }


    private function deleteCurrent(): void
    {
        $this->ssh->run(
            $this->target,
            'rm -f '. Ssh::quote($this->target->root .'/.deploy/current.json'),
        );
    }


    /** @return null|array<string, mixed> */
    private function readCurrent(): ?array
    {
        $result = $this->ssh->must(
            $this->target,
            'if [ -f '. Ssh::quote($this->target->root .'/.deploy/current.json') .' ]; '
            .'then cat '. Ssh::quote($this->target->root .'/.deploy/current.json')
            .'; else printf __MISSING__; fi',
        );

        if (trim($result->stdout) === '__MISSING__' || trim($result->stdout) === '') {
            return null;
        }

        $data = json_decode($result->stdout, true);

        if ( ! is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;

    }


    private function currentReleaseId(): ?string
    {
        $current = $this->readCurrent();
        $releaseId = $current['releaseId'] ?? null;

        return is_string($releaseId) && $releaseId !== '' ? $releaseId : null;
    }


    /** @param array<string, mixed> $data */
    private function writeRemoteJson(string $path, array $data): void
    {
        try {
            $json = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ) ."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('could not encode remote JSON: '. $exception->getMessage(), 0, $exception);
        }

        $this->writeRemoteFile($path, $json);
    }


    private function writeRemoteFile(string $path, string $contents): void
    {
        $this->ssh->must(
            $this->target,
            'cat > '. Ssh::quote($path),
            $contents,
        );
    }


    private function download(string $remote, string $local): void
    {
        $target = $this->target;
        $scp = [
            'scp',
            '-P', (string) $target->sshPort,
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=15',
        ];

        $identity = $target->identityPath();

        if ($identity !== null) {
            $scp[] = '-i';
            $scp[] = $identity;
        }

        $scp[] = $target->sshTarget() .':'. $remote;
        $scp[] = $local;

        $this->shell->mustRun($scp);
    }


        /** @return array{archive: string, manifest: ReleaseManifest, temporary: bool} */
    public function exportRelease(): array
    {
        $current = $this->readCurrent();

        if ($current === null) {
            throw new RuntimeException('source has no current release');
        }

        $status = $current['status'] ?? null;

        if ($status !== null && $status !== 'current') {
            throw new RuntimeException('source is not in current state');
        }

        $releaseId = $current['releaseId'] ?? null;

        if ( ! is_string($releaseId) || $releaseId === '') {
            throw new RuntimeException('source has no current release');
        }

        $remoteArchive = $this->target->root .'/.deploy/releases/'. $releaseId .'/release.tar.gz';
        $remoteManifest = $this->target->root .'/.deploy/releases/'. $releaseId .'/manifest.json';
        $available = $this->ssh->run(
            $this->target,
            'test -f '. Ssh::quote($remoteArchive)
            .' && test -f '. Ssh::quote($remoteManifest),
        );

        if ( ! $available->ok()) {
            throw new RuntimeException("source release artifact not found: {$releaseId}");
        }

        $directory = sys_get_temp_dir() .'/stougeiro-release-'. bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);

        $archive = $directory .'/release.tar.gz';
        $manifestPath = $directory .'/manifest.json';

        $this->download($remoteArchive, $archive);
        $this->download($remoteManifest, $manifestPath);

        $raw = file_get_contents($manifestPath);

        if ($raw === false) {
            throw new RuntimeException('could not read downloaded release manifest');
        }

        $data = json_decode($raw, true);

        if ( ! is_array($data)) {
            throw new RuntimeException('invalid downloaded release manifest');
        }

        /** @var array<string, mixed> $data */
        $manifest = ReleaseManifest::fromArray($data);

        if ($manifest->artifactHash !== null) {
            $actual = (new ArtifactVerifier())->hash($archive);

            if ( ! hash_equals($manifest->artifactHash, $actual)) {
                throw new RuntimeException("artifact hash mismatch: {$releaseId}");
            }
        }

        return [
            'archive' => $archive,
            'manifest' => $manifest,
            'temporary' => true,
        ];
    }



        public function receiveRelease(string $archive, ReleaseManifest $manifest, string $promotedFrom, bool $forceLock = false): void
    {
        $lock = new TargetLock($this->target, $this->shell, $this->operator, 'promote');
        $previousOwner = $lock->acquire($forceLock);

        if ($previousOwner !== null) {
            $lockReleaseId = $this->currentReleaseId() ?? 'lock';
            $this->writeEvent('lock.override', $lockReleaseId, null, $this->target->name, null, [
                'forcedLock' => true,
                'previousOwner' => $previousOwner,
            ]);
        }

        $started = false;
        $snapshot = null;
        $remoteArchive = $this->target->root .'/.deploy/incoming-release-'. bin2hex(random_bytes(4)) .'.tar.gz';

        try {
            $this->check();
            $this->assertEnvExists();

            $previousReleaseId = $this->currentReleaseId();
            $store = new ReleaseStore($this->projectRoot);

            $this->maintenance->enable($this->target);
            $started = true;

            $this->ensureRemoteDirs();
            $snapshot = $this->snapshot($manifest->toArray(), $previousReleaseId);
            $this->out('backup: '. $snapshot);

            $this->upload($archive, $remoteArchive);
            $this->extractRelease($remoteArchive);
            $this->applyOverrides();

            $this->maintenance->disable($this->target);
            $this->verify();

            $store->persist($manifest);
            $artifactVerified = $this->uploadRelease($manifest, $archive);
            $this->writeCurrent($manifest, $snapshot, 'current', $previousReleaseId, $promotedFrom);
            $this->writeLocalTarget([
                'releaseId' => $manifest->releaseId,
                'contentHash' => $manifest->contentHash,
                'version' => $manifest->version,
                'status' => 'current',
                'backup' => $snapshot,
                'promotedFrom' => $promotedFrom,
            ]);
            $this->writeEvent('promote', $manifest->releaseId, $promotedFrom, $this->target->name, $snapshot, [
                'artifactVerified' => $artifactVerified,
            ]);

            $this->pruneBackups();
            $this->pruneReleases($manifest->releaseId);
            $this->pruneEvents();
            $this->out('release: '. $manifest->releaseId .' ('. $manifest->version .')');
            $this->out('promote complete');
        }
        catch (Throwable $error) {
            if ($started) {
                $this->out('error: '. $error->getMessage());

                if ($snapshot === null) {
                    $this->out('no backup: maintenance remains active');
                } else {
                    try {
                        $this->maintenance->enable($this->target);
                        $this->restore($snapshot);
                        $this->applyOverrides();
                        $this->maintenance->disable($this->target);
                        $this->verify();
                        $this->out('rollback complete');
                    } catch (Throwable $rollback) {
                        $this->out('ROLLBACK FAILED, maintenance remains active: '. $rollback->getMessage());
                    }
                }
            }

            throw $error;
        }
        finally {
            $lock->release();
        }
    }



    public function retire(string $redirectUrl, string $promotedTo): void
    {
        if ($this->target->nestedWebroots !== []) {
            throw new RuntimeException('cannot retire a target with nested webroots');
        }

        $current = $this->readCurrent();

        if ($current === null) {
            throw new RuntimeException('source has no current release');
        }

        $status = $current['status'] ?? null;

        if ($status !== null && $status !== 'current') {
            throw new RuntimeException('source is not in current state');
        }

        $stub = (new RedirectStub($this->projectRoot))->render($redirectUrl);
        $started = false;
        $snapshot = null;

        try {
            $this->maintenance->enable($this->target);
            $started = true;
            $snapshot = $this->snapshot($current, null);

            $this->writeRemoteFile($this->target->webroot .'/index.php', $stub['index']);
            $this->writeRemoteFile($this->target->webroot .'/.htaccess', $stub['htaccess']);

            $this->maintenance->disable($this->target);
            $this->assertRedirect($this->target->url, $redirectUrl);

            $this->removeSourceDeployment();

            $this->writeRemoteJson($this->target->root .'/.deploy/current.json', [
                'releaseId' => $current['releaseId'] ?? null,
                'contentHash' => $current['contentHash'] ?? null,
                'version' => $current['version'] ?? null,
                'status' => 'redirected',
                'redirectTo' => $redirectUrl,
                'promotedTo' => $promotedTo,
                'promotedAt' => gmdate('c'),
            ]);

            $this->writeLocalTarget([
                'releaseId' => $current['releaseId'] ?? null,
                'contentHash' => $current['contentHash'] ?? null,
                'version' => $current['version'] ?? null,
                'status' => 'redirected',
                'redirectTo' => $redirectUrl,
                'promotedTo' => $promotedTo,
            ]);

            $retireRelease = $current['releaseId'] ?? 'unknown';
            $this->writeEvent('retire', is_string($retireRelease) ? $retireRelease : 'unknown', $this->target->name, $promotedTo, $snapshot);

            $this->out('source retired: redirecting to '. $redirectUrl);
        }
        catch (Throwable $error) {
            if ($started && $snapshot !== null) {
                try {
                    $this->maintenance->enable($this->target);
                    $this->restore($snapshot);
                    $this->applyOverrides();
                } catch (Throwable $rollback) {
                    $this->out('RETIRE ROLLBACK FAILED, maintenance remains active: '. $rollback->getMessage());
                }
            }

            throw $error;
        }
    }


    private function extractRelease(string $remoteArchive): void
    {
        $target = $this->target;

        $delete = 'find '. Ssh::quote($target->webroot) .' -mindepth 1 -maxdepth 1'
            .' ! -name '. Ssh::quote('.maintenance');

        foreach ($target->nestedWebroots as $entry) {
            $delete .= ' ! -name '. Ssh::quote($entry);
        }

        $delete .= ' -exec rm -rf {} +';

        $applicationDelete = 'find '. Ssh::quote($target->root .'/application') .' -mindepth 1 -maxdepth 1'
            .' ! -name '. Ssh::quote('storage')
            .' -exec rm -rf {} +';

        $this->ssh->must(
            $target,
            'rm -rf '. Ssh::quote($target->root .'/vendor')
            .' && '. $applicationDelete
            .' && tar -xzf '. Ssh::quote($remoteArchive)
            .' -C '. Ssh::quote($target->root)
            .' application vendor composer.json composer.lock'
            .' && '. $delete
            .' && tar -xzf '. Ssh::quote($remoteArchive)
            .' -C '. Ssh::quote($target->webroot)
            .' --strip-components=1 public'
            .' && rm -f '. Ssh::quote($remoteArchive),
        );
    }


    private function removeSourceDeployment(): void
    {
        $target = $this->target;

        $delete = 'find '. Ssh::quote($target->webroot) .' -mindepth 1 -maxdepth 1'
            .' ! -name '. Ssh::quote('index.php')
            .' ! -name '. Ssh::quote('.htaccess')
            .' -exec rm -rf {} +';

        $this->ssh->must(
            $target,
            'rm -rf '. Ssh::quote($target->root .'/application')
            .' '. Ssh::quote($target->root .'/vendor')
            .' && '. $delete,
        );
    }


    private function assertRedirect(string $url, string $expected): void
    {
        $result = $this->shell->run([
            'curl', '-sS', '-o', '/dev/null', '-w', '%{http_code} %{redirect_url}',
            '--max-time', '20', $url,
        ]);

        $parts = preg_split('/\s+/', trim($result->output())) ?: [];
        $status = $parts[0] ?? '000';
        $location = $parts[1] ?? '';

        if ($status !== '302' || rtrim($location, '/') !== rtrim($expected, '/')) {
            throw new RuntimeException(
                "retire: expected 302 to {$expected}, got {$status} {$location}",
            );
        }

        $this->out('retire verify: 302 to '. $expected);
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
