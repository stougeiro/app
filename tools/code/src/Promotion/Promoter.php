<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Promotion;

use RuntimeException;
use Throwable;
use Sourcerer\Tool\Contract\OutputInterface;
use Sourcerer\Tool\Code\Backup\SnapshotStore;
use Sourcerer\Tool\Code\Event\EventWriter;
use Sourcerer\Tool\Code\Lock\TargetLock;
use Sourcerer\Tool\Code\Maintenance;
use Sourcerer\Tool\Contract\Operator;
use Sourcerer\Tool\Code\Preflight\Preflight;
use Sourcerer\Tool\Code\Release\ReleaseExporter;
use Sourcerer\Tool\Code\Release\ReleaseManifest;
use Sourcerer\Tool\Code\Release\ReleasePublisher;
use Sourcerer\Tool\Code\Release\ReleaseStore;
use Sourcerer\Tool\Code\Remote\RemoteFiles;
use Sourcerer\Tool\Code\Remote\Scp;
use Sourcerer\Tool\Code\Retirement\RedirectStub;
use Sourcerer\Tool\Code\Shell;
use Sourcerer\Tool\Code\Ssh;
use Sourcerer\Tool\Code\State\LocalTargetState;
use Sourcerer\Tool\Code\Target;
use Sourcerer\Tool\Code\Transport\Transfer;
use Sourcerer\Tool\Code\Verify\Verifier;


final class Promoter
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


    /** @return array{archive: string, manifest: ReleaseManifest, temporary: bool} */
    public function export(): array
    {
        return (new ReleaseExporter($this->target, $this->ssh, $this->scp, $this->remote))->export();
    }


    public function receive(string $archive, ReleaseManifest $manifest, string $promotedFrom, bool $forceLock = false): void
    {
        $lock = new TargetLock($this->target, $this->shell, $this->operator, 'promote');
        $previousOwner = $lock->acquire($forceLock);

        if ($previousOwner !== null) {
            $lockReleaseId = $this->remote->currentReleaseId() ?? 'lock';
            $this->events->write('lock.override', $lockReleaseId, null, $this->target->name, null, [
                'forcedLock' => true,
                'previousOwner' => $previousOwner,
            ]);
        }

        $started = false;
        $snapshot = null;
        $hasRsync = false;
        $remoteArchive = $this->target->root .'/.deploy/incoming-release-'. bin2hex(random_bytes(4)) .'.tar.gz';

        try {
            $hasRsync = $this->preflight->check();
            $this->preflight->assertEnvExists();

            $previousReleaseId = $this->remote->currentReleaseId();
            $store = new ReleaseStore($this->projectRoot);

            $this->maintenance->enable($this->target);
            $started = true;

            $this->transfer->ensureRemoteDirs();
            $snapshot = $this->snapshots->create($manifest->toArray(), $previousReleaseId);
            $this->out('backup: '. $snapshot);

            $this->scp->upload($archive, $remoteArchive);
            $this->extractRelease($remoteArchive);
            $this->transfer->applyOverrides($hasRsync);

            $this->maintenance->disable($this->target);
            $this->verifier->verify();

            $store->persist($manifest);
            $artifactVerified = $this->publisher->upload($manifest, $archive);
            $this->remote->writeCurrent($manifest, $snapshot, 'current', $previousReleaseId, $promotedFrom);
            $this->local->write([
                'releaseId' => $manifest->releaseId,
                'contentHash' => $manifest->contentHash,
                'version' => $manifest->version,
                'status' => 'current',
                'backup' => $snapshot,
                'promotedFrom' => $promotedFrom,
            ]);
            $this->events->write('promote', $manifest->releaseId, $promotedFrom, $this->target->name, $snapshot, [
                'artifactVerified' => $artifactVerified,
            ]);

            $this->snapshots->pruneBackups();
            $this->snapshots->pruneReleases($manifest->releaseId);
            $this->events->prune($this->target->eventKeep);
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
                        $this->snapshots->restore($snapshot);
                        $this->transfer->applyOverrides($hasRsync);
                        $this->maintenance->disable($this->target);
                        $this->verifier->verify();
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

        $current = $this->remote->readCurrent();

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
            $snapshot = $this->snapshots->create($current, null);

            $this->remote->writeFile($this->target->webroot .'/index.php', $stub['index']);
            $this->remote->writeFile($this->target->webroot .'/.htaccess', $stub['htaccess']);

            $this->maintenance->disable($this->target);
            $this->verifier->assertRedirect($this->target->url, $redirectUrl);

            $this->removeSourceDeployment();

            $this->remote->writeJson($this->target->root .'/.deploy/current.json', [
                'releaseId' => $current['releaseId'] ?? null,
                'contentHash' => $current['contentHash'] ?? null,
                'version' => $current['version'] ?? null,
                'status' => 'redirected',
                'redirectTo' => $redirectUrl,
                'promotedTo' => $promotedTo,
                'promotedAt' => gmdate('c'),
            ]);

            $this->local->write([
                'releaseId' => $current['releaseId'] ?? null,
                'contentHash' => $current['contentHash'] ?? null,
                'version' => $current['version'] ?? null,
                'status' => 'redirected',
                'redirectTo' => $redirectUrl,
                'promotedTo' => $promotedTo,
            ]);

            $retireRelease = $current['releaseId'] ?? 'unknown';
            $this->events->write('retire', is_string($retireRelease) ? $retireRelease : 'unknown', $this->target->name, $promotedTo, $snapshot);

            $this->out('source retired: redirecting to '. $redirectUrl);
        }
        catch (Throwable $error) {
            if ($started && $snapshot !== null) {
                try {
                    $this->maintenance->enable($this->target);
                    $this->snapshots->restore($snapshot);
                    $this->transfer->applyOverrides(false);
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


    private function out(string $message): void
    {
        $this->output->line('['. $this->target->name .'] '. $message);
    }
}
