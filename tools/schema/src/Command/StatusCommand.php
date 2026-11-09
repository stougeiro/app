<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Command;

use Sourcerer\Tool\Contract\ExitCode;
use Sourcerer\Tool\Contract\InputInterface;
use Sourcerer\Tool\Contract\Option;
use Sourcerer\Tool\Contract\OutputInterface;
use Sourcerer\Tool\Schema\Canonical\SchemaHasher;
use Sourcerer\Tool\Schema\Config\DatabaseConfig;
use Sourcerer\Tool\Schema\Connection;
use Sourcerer\Tool\Schema\Domain\SchemaState;
use Sourcerer\Tool\Schema\Driver\SqliteDriver;
use Sourcerer\Tool\Schema\Repository\SchemaRepository;


final class StatusCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'schema status';
    }

    public function description(): string
    {
        return 'Show local schema state or compare a target database';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            new Option('database', 'Database name', true, null, false, 'name'),
            new Option('target', 'Target to compare', true, null, false, 'name'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->database($input);
        $repository = new SchemaRepository($this->projectRoot, $database->name);

        if ( ! $repository->initialized()) {
            $this->out($output, $database->name, 'not initialized');

            return ExitCode::Failure->value;
        }

        $target = $input->string('target');

        if ($target === null || $target === '') {
            return $this->local($output, $database->name, $repository);
        }

        return $this->target($output, $database, $repository, $target);
    }


    private function local(OutputInterface $output, string $database, SchemaRepository $repository): int
    {
        $genesis = $repository->genesis();
        $state = $repository->genesisState();

        if ($genesis === null || $state === null) {
            $this->out($output, $database, 'invalid genesis');

            return ExitCode::CheckFailed->value;
        }

        $hash = (new SchemaHasher())->hash(SchemaState::fromArray($state));
        $expected = $genesis['schemaHash'] ?? null;
        $releaseSqlPath = rtrim($repository->projectRoot(), '/') .'/schema/'. $database .'/genesis/release.sql';
        $releaseSql = is_file($releaseSqlPath) ? file_get_contents($releaseSqlPath) : false;
        $releaseHash = is_string($releaseSql) ? 'sha256:'. hash('sha256', $releaseSql) : null;
        $checkpoint = $repository->checkpoint('GENESIS');

        $this->out($output, $database, 'HEAD: '. ($repository->head() ?? '-'));
        $this->out($output, $database, 'genesis: '. (is_string($expected) ? $expected : '-'));
        $this->out($output, $database, 'schemaHash: '. $hash);
        $this->out($output, $database, 'checkpoint: '. ($checkpoint === null ? 'missing' : 'GENESIS'));
        $this->out($output, $database, 'stage: '. ($repository->stageExists() ? 'pending' : 'none'));

        $artifacts = $genesis['artifacts'] ?? null;
        $releaseArtifact = is_array($artifacts) ? ($artifacts['release'] ?? null) : null;

        $valid = is_string($expected)
            && hash_equals($expected, $hash)
            && $releaseHash !== null
            && $releaseArtifact === $releaseHash
            && $checkpoint !== null
            && ($checkpoint['schemaHash'] ?? null) === $hash;

        $this->out($output, $database, $valid ? 'local: OK' : 'local: invalid');

        return $valid ? ExitCode::Success->value : ExitCode::CheckFailed->value;
    }


    private function target(
        OutputInterface $output,
        DatabaseConfig $database,
        SchemaRepository $repository,
        string $target,
    ): int {
        $targetConfig = $database->target($target);

        if ( ! $targetConfig->isLocal()) {
            $this->out($output, $database->name, 'transport: '. $targetConfig->transport);
        }

        $pdo = Connection::connect($this->projectRoot, $database, $targetConfig, true);
        $driver = new SqliteDriver();
        $unsupported = $driver->unsupportedObjects($pdo);

        if ($unsupported !== []) {
            foreach ($unsupported as $object) {
                $this->out($output, $database->name, 'unsupported: '. $object);
            }

            return ExitCode::CheckFailed->value;
        }

        $state = $driver->inspect($pdo);
        $hash = (new SchemaHasher())->hash($state);
        $genesis = $repository->genesis();
        $genesisHash = $genesis['schemaHash'] ?? null;

        $this->out($output, $database->name, 'target: '. $target);
        $this->out($output, $database->name, 'driver: sqlite '. $driver->version($pdo));
        $this->out($output, $database->name, 'schemaHash: '. $hash);
        $this->out($output, $database->name, 'release: '. ($repository->head() ?? '-'));

        if (is_string($genesisHash) && hash_equals($genesisHash, $hash)) {
            $this->out($output, $database->name, 'state: CLEAN');

            return ExitCode::Success->value;
        }

        $this->out($output, $database->name, 'state: DRIFT');

        return ExitCode::Success->value;
    }


    private function out(OutputInterface $output, string $database, string $message): void
    {
        $output->line('['. $database .'] '. $message);
    }
}
