<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code;

use RuntimeException;
use Throwable;
use Sourcerer\Tool\Contract\OutputInterface;
use Sourcerer\Tool\Code\Backup\SnapshotStore;
use Sourcerer\Tool\Code\Event\EventWriter;
use Sourcerer\Tool\Code\Lock\TargetLock;
use Sourcerer\Tool\Contract\Operator;
use Sourcerer\Tool\Code\Preflight\Preflight;
use Sourcerer\Tool\Code\Release\ArtifactVerifier;
use Sourcerer\Tool\Code\Release\ReleaseHasher;
use Sourcerer\Tool\Code\Release\ReleaseId;
use Sourcerer\Tool\Code\Release\ReleaseManifest;
use Sourcerer\Tool\Code\Release\ReleasePublisher;
use Sourcerer\Tool\Code\Release\ReleaseStore;
use Sourcerer\Tool\Code\Remote\RemoteFiles;
use Sourcerer\Tool\Code\Remote\Scp;
use Sourcerer\Tool\Code\State\LocalTargetState;
use Sourcerer\Tool\Support\FileSystem;
use Sourcerer\Tool\Code\Transport\Transfer;
use Sourcerer\Tool\Code\Verify\Verifier;


class Deployer
{
    private readonly Ssh $ssh;

    private readonly Maintenance $maintenance;

    private readonly Scp $scp;

    private readonly RemoteFiles $remote;

    private readonly SnapshotStore $snapshots;

    private readonly Transfer $transfer;

    private readonly Preflight $preflight;

    private readonly ReleasePublisher $publisher;

    private readonly Verifier $verifier;

    private readonly EventWriter $events;

    private readonly LocalTargetState $local;

    private bool $hasRsync = false;


    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly OutputInterface $output,
        private readonly Operator $operator,
    ) {
        $this->ssh = new Ssh($shell);
        $this->maintenance = new Maintenance($this->ssh);
        $this->scp = new Scp($target, $shell);
        $this->remote = new RemoteFiles($target, $this->ssh);
        $this->snapshots = new SnapshotStore($target, $this->ssh);
        $this->transfer = new Transfer($projectRoot, $target, $shell, $this->ssh, $this->scp, $output);
        $this->preflight = new Preflight($projectRoot, $target, $shell, $this->ssh, $this->remote, $output);
        $this->publisher = new ReleasePublisher($projectRoot, $target, $shell, $this->ssh, $this->scp, $this->remote, $output);
        $this->verifier = new Verifier($target, $shell, $output);
        $this->events = new EventWriter($target, $operator, $output, $projectRoot, $shell);
        $this->local = new LocalTargetState($projectRoot, $target->name);
    }


    public function check(): void
    {
        $this->hasRsync = $this->preflight->check();
    }


    public function push(bool $forceLock = false): void
    {
        $lock = new TargetLock($this->target, $this->shell, $this->operator, 'push');
        $previousOwner = $lock->acquire($forceLock);

        if ($previousOwner !== null) {
            $lockReleaseId = $this->remote->currentReleaseId() ?? 'lock';
            $this->events->write('lock.override', $lockReleaseId, null, $this->target->name, null, [
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
            $this->preflight->assertEnvExists();

            $contentHash = (new ReleaseHasher())->hash($this->projectRoot);
            $releaseId = ReleaseId::fromHash($contentHash);
            $version = $this->releaseVersion();
            $previousReleaseId = $this->remote->currentReleaseId();

            $build = $this->buildVendor();
            $releaseArchive = $this->publisher->createArchive($build['vendor']);
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

            $this->transfer->ensureRemoteDirs();

            $snapshot = $this->snapshots->create($manifest->toArray(), $previousReleaseId);
            $this->out('backup: '. $snapshot);

            $this->transfer->run($build['vendor'], $this->hasRsync);

            $this->out('disabling maintenance');
            $this->maintenance->disable($this->target);

            $this->verifier->verify();

            $store->persist($manifest);
            $artifactVerified = $this->publisher->upload($manifest, $releaseArchive);
            $this->remote->writeCurrent($manifest, $snapshot, 'current');
            $this->local->write([
                'releaseId' => $manifest->releaseId,
                'contentHash' => $manifest->contentHash,
                'version' => $manifest->version,
                'status' => 'current',
                'backup' => $snapshot,
            ]);
            $this->events->write('push', $manifest->releaseId, null, $this->target->name, $snapshot, [
                'artifactVerified' => $artifactVerified,
            ]);

            $this->snapshots->pruneBackups();
            $this->snapshots->pruneReleases($manifest->releaseId);
            $this->events->prune($this->target->eventKeep);
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
                        $this->snapshots->restore($snapshot);
                        $this->transfer->applyOverrides($this->hasRsync);
                        $this->maintenance->disable($this->target);
                        $this->verifier->verify();
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

            if ($releaseArchive !== null && ! FileSystem::tryDelete($releaseArchive)) {
                $this->out('warning: could not remove temporary release archive');
            }

            if ($build !== null) {
                try {
                    FileSystem::removeDir($build['dir']);
                } catch (Throwable $cleanup) {
                    $this->out('warning: could not remove build directory: '. $cleanup->getMessage());
                }
            }
        }
    }


    /**
     * @return list<string>
     */
    public function backups(): array
    {
        return $this->snapshots->backups();
    }


    public function rollback(?string $timestamp): void
    {
        $this->check();
        $this->preflight->assertEnvExists();

        $backups = $this->snapshots->backups();

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
            $restored = $this->snapshots->restore($timestamp);
            $previousReleaseId = $restored['previousReleaseId'] ?? null;

            if (is_string($previousReleaseId) && $previousReleaseId !== '') {
                $this->remote->writeCurrentReleaseId($previousReleaseId);
                $this->local->write(['releaseId' => $previousReleaseId, 'status' => 'current']);
            } else {
                $this->remote->deleteCurrent();
                $this->local->write(['status' => 'maintenance']);
            }

            $this->transfer->applyOverrides($this->hasRsync);
            $this->maintenance->disable($this->target);
            $this->verifier->verify();
            $rollbackRelease = $restored['previousReleaseId'] ?? $restored['releaseId'] ?? 'unknown';
            $this->events->write('rollback', is_string($rollbackRelease) ? $rollbackRelease : 'unknown', null, null, $timestamp);
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
        $directory = FileSystem::temporaryDirectory();

        try
        {
            FileSystem::copyFile($this->projectRoot .'/composer.json', $directory .'/composer.json');
            FileSystem::copyFile($this->projectRoot .'/composer.lock', $directory .'/composer.lock');
            FileSystem::copyDir($this->projectRoot .'/application', $directory .'/application', ['storage', 'env.json']);

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
            FileSystem::removeDir($directory);
            throw $error;
        }
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
}
