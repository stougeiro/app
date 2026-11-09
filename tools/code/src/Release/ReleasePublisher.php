<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Release;

use JsonException;
use RuntimeException;
use Sourcerer\Tool\Contract\OutputInterface;
use Sourcerer\Tool\Code\Remote\RemoteFiles;
use Sourcerer\Tool\Code\Remote\Scp;
use Sourcerer\Tool\Code\Shell;
use Sourcerer\Tool\Code\Ssh;
use Sourcerer\Tool\Support\FileSystem;
use Sourcerer\Tool\Code\Target;


final class ReleasePublisher
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly Ssh $ssh,
        private readonly Scp $scp,
        private readonly RemoteFiles $remote,
        private readonly OutputInterface $output,
    ) {
    }


    public function createArchive(string $vendor): string
    {
        $archive = FileSystem::temporaryArchive();

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


    public function upload(ReleaseManifest $manifest, string $archive): ?bool
    {
        $target = $this->target;
        $directory = $target->root .'/.deploy/releases/'. $manifest->releaseId;
        $remoteArchive = $directory .'/release.tar.gz';

        $this->ssh->must($target, 'mkdir -p '. Ssh::quote($directory));
        $this->scp->upload($archive, $remoteArchive);

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

        $this->remote->writeFile($directory .'/manifest.json', $json);

        return $verified;
    }


    private function out(string $message): void
    {
        $this->output->line('['. $this->target->name .'] '. $message);
    }
}
