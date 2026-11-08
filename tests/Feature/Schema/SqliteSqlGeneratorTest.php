<?php declare(strict_types=1);

use Sourcerer\Tool\Schema\Canonical\SchemaHasher;
use Sourcerer\Tool\Schema\Driver\SqliteDriver;
use Sourcerer\Tool\Schema\Sql\SqliteSqlGenerator;
use Tests\Feature\Schema\SchemaFixtures;


test('release sql reproduces the same schema hash', function () {
    $source = SchemaFixtures::sqlite(
        sys_get_temp_dir() .'/schema-source-'. bin2hex(random_bytes(6)) .'.sqlite',
        <<<'SQL'
CREATE TABLE teams (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL
);

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER,
    email TEXT NOT NULL UNIQUE,
    score INTEGER DEFAULT 0,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
);

CREATE INDEX idx_users_score ON users(score) WHERE score > 0;

CREATE VIEW active_users AS
SELECT * FROM users WHERE score > 0;
SQL,
    );

    $driver = new SqliteDriver();
    $hasher = new SchemaHasher();
    $state = $driver->inspect($source);
    $sql = (new SqliteSqlGenerator())->release($state);

    expect($sql)->toContain('CREATE TABLE "teams"')
        ->and($sql)->toContain('CREATE TABLE "users"')
        ->and($sql)->toContain('UNIQUE ("email")')
        ->and($sql)->toContain('FOREIGN KEY ("team_id")')
        ->and($sql)->toContain('CREATE INDEX idx_users_score')
        ->and($sql)->toContain('CREATE VIEW active_users');

    $target = SchemaFixtures::sqlite(
        sys_get_temp_dir() .'/schema-target-'. bin2hex(random_bytes(6)) .'.sqlite',
        $sql,
    );

    $targetState = $driver->inspect($target);

    expect($hasher->hash($targetState))->toBe($hasher->hash($state));
});
