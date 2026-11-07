<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Release;

use RuntimeException;
use Sourcerer\Tool\Code\Remote\RemoteFiles;
use Sourcerer\Tool\Code\Remote\Scp;
use Sourcerer\Tool\Code\Ssh;
use Sourcerer\Tool\Code\Support\FileSystem;
use Sourcerer\Tool\Code\Target;


final class ReleaseExporter
{
    public function __construct(
        private readonly Target $target,
        private readonly Ssh $ssh,
        private readonly Scp $scp,
        private readonly RemoteFiles $remote,
    ) {
    }


    /** @return array{archive: string, manifest: ReleaseManifest, temporary: bool} */
    public function export(): array
    {
        $current = $this->remote->readCurrent();

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

        $directory = FileSystem::temporaryDirectory('stougeiro-release-');

        $archive = $directory .'/release.tar.gz';
        $manifestPath = $directory .'/manifest.json';

        $this->scp->download($remoteArchive, $archive);
        $this->scp->download($remoteManifest, $manifestPath);

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
}
