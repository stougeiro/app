<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Repository;

use JsonException;
use RuntimeException;
use Sourcerer\Tool\Schema\Canonical\CanonicalJson;
use Sourcerer\Tool\Schema\Config\DatabaseConfig;
use Sourcerer\Tool\Schema\Domain\SchemaState;


final class SchemaRepository
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly string $database,
    ) {
    }


    public function initialized(): bool
    {
        return is_file($this->headPath()) || is_dir($this->genesisDirectory());
    }


    public function head(): ?string
    {
        if ( ! is_file($this->headPath())) {
            return null;
        }

        $value = trim((string) file_get_contents($this->headPath()));

        return $value === '' ? null : $value;
    }


    /** @return array<string, mixed>|null */
    public function genesis(): ?array
    {
        return $this->readJson($this->genesisDirectory() .'/genesis.json');
    }


    /** @return array<string, mixed>|null */
    public function genesisState(): ?array
    {
        return $this->readJson($this->genesisDirectory() .'/schema.json');
    }


    /** @return array<string, mixed>|null */
    public function checkpoint(string $releaseId): ?array
    {
        return $this->readJson($this->checkpointPath($releaseId));
    }


    /** @return array<string, array<string, mixed>> */
    public function checkpoints(): array
    {
        $files = glob($this->checkpointsDirectory() .'/*.json') ?: [];
        $checkpoints = [];

        foreach ($files as $file) {
            $id = basename($file, '.json');
            $data = $this->readJson($file);

            if ($data !== null) {
                $checkpoints[$id] = $data;
            }
        }

        ksort($checkpoints, SORT_STRING);

        return $checkpoints;
    }


    public function stageExists(): bool
    {
        return is_file($this->localDirectory() .'/stage.json');
    }


    public function writeGenesis(
        DatabaseConfig $database,
        SchemaState $state,
        string $schemaHash,
        string $releaseSql,
        string $driverVersion,
    ): void {
        if ($this->initialized()) {
            throw new RuntimeException("schema: database '{$this->database}' is already initialized");
        }

        $this->ensureDirectory($this->genesisDirectory());
        $this->ensureDirectory($this->checkpointsDirectory());

        $releaseHash = 'sha256:'. hash('sha256', $releaseSql);
        $now = gmdate('c');

        $this->writeJson($this->genesisDirectory() .'/schema.json', $state->toArray());
        $this->writeJson($this->genesisDirectory() .'/genesis.json', [
            'format' => 1,
            'database' => $this->database,
            'id' => 'GENESIS',
            'driver' => $database->driver,
            'driverVersion' => $driverVersion,
            'schemaHash' => $schemaHash,
            'createdAt' => $now,
            'artifacts' => [
                'release' => $releaseHash,
            ],
        ]);
        $this->writeFile($this->genesisDirectory() .'/release.sql', $releaseSql);
        $this->writeJson($this->checkpointPath('GENESIS'), [
            'format' => 1,
            'release' => 'GENESIS',
            'schemaHash' => $schemaHash,
            'baseCheckpoint' => null,
            'source' => 'genesis',
            'verifiedAt' => $now,
            'artifacts' => [
                'release' => $releaseHash,
            ],
        ]);
        $this->writeFile($this->headPath(), "GENESIS\n");
    }


    public function projectRoot(): string
    {
        return $this->projectRoot;
    }


    public function database(): string
    {
        return $this->database;
    }


    private function rootDirectory(): string
    {
        return rtrim($this->projectRoot, '/') .'/schema/'. $this->database;
    }


    private function genesisDirectory(): string
    {
        return $this->rootDirectory() .'/genesis';
    }


    private function checkpointsDirectory(): string
    {
        return $this->rootDirectory() .'/checkpoints';
    }


    private function headPath(): string
    {
        return $this->rootDirectory() .'/HEAD';
    }


    private function checkpointPath(string $releaseId): string
    {
        return $this->checkpointsDirectory() .'/'. $releaseId .'.json';
    }


    private function localDirectory(): string
    {
        return rtrim($this->projectRoot, '/') .'/.schema/'. $this->database;
    }


    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        if ( ! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if ( ! is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }


    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->writeFile($path, CanonicalJson::pretty($data));
    }


    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        $this->ensureDirectory($directory);

        $temporary = tempnam($directory, '.schema-');

        if ($temporary === false) {
            throw new RuntimeException("schema: could not create temporary file in {$directory}");
        }

        if (file_put_contents($temporary, $contents) === false) {
            @unlink($temporary);
            throw new RuntimeException("schema: could not write {$path}");
        }

        @chmod($temporary, 0644);

        if ( ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("schema: could not move temporary file to {$path}");
        }
    }


    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if ( ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("schema: could not create directory {$directory}");
        }
    }
}
