<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Release;

use JsonException;
use RuntimeException;


final class ReleaseStore
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }


    public function manifestPath(string $releaseId): string
    {
        return $this->directory($releaseId) .'/manifest.json';
    }

    public function exists(string $releaseId): bool
    {
        return is_file($this->manifestPath($releaseId));
    }

    public function persist(ReleaseManifest $manifest): void
    {
        $directory = $this->directory($manifest->releaseId);

        if ( ! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("could not create release directory: {$directory}");
        }

        try {
            $json = json_encode(
                $manifest->toArray(),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('could not encode release manifest: '. $exception->getMessage(), 0, $exception);
        }

        if (file_put_contents($this->manifestPath($manifest->releaseId), $json ."\n") === false) {
            throw new RuntimeException("could not write release manifest: {$manifest->releaseId}");
        }
    }

    public function manifest(string $releaseId): ReleaseManifest
    {
        $path = $this->manifestPath($releaseId);

        if ( ! is_file($path)) {
            throw new RuntimeException("release manifest not found: {$releaseId}");
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("could not read release manifest: {$releaseId}");
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("invalid release manifest: {$releaseId}", 0, $exception);
        }

        if ( ! is_array($data)) {
            throw new RuntimeException("invalid release manifest: {$releaseId}");
        }

        /** @var array<string, mixed> $data */
        return ReleaseManifest::fromArray($data);
    }

    private function directory(string $releaseId): string
    {
        return rtrim($this->projectRoot, '/') .'/.deploy/releases/'. $releaseId;
    }
}
