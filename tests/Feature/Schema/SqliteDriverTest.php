<?php declare(strict_types=1);

use Sourcerer\Tool\Schema\Driver\SqliteDriver;
use Tests\Feature\Schema\SchemaFixtures;


function sqliteDriverDatabase(): PDO
{
    $path = sys_get_temp_dir() .'/schema-driver-'. bin2hex(random_bytes(6)) .'.sqlite';

    return SchemaFixtures::sqlite($path, <<<'SQL'
CREATE TABLE teams (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL
);

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER,
    email TEXT NOT NULL UNIQUE DEFAULT 'user@example.com',
    score INTEGER,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
);

CREATE INDEX idx_users_score ON users(score) WHERE score > 0;

CREATE VIEW active_users AS
SELECT * FROM users WHERE score > 0;
SQL);
}


test('inspects sqlite structure', function () {
    $pdo = sqliteDriverDatabase();
    $state = (new SqliteDriver())->inspect($pdo);

    expect(array_keys($state->tables))->toBe(['teams', 'users'])
        ->and(array_keys($state->views))->toBe(['active_users']);

    $users = $state->tables['users'];

    expect(array_keys($users->columns))->toBe(['id', 'team_id', 'email', 'score'])
        ->and($users->primaryKey)->toBe(['id'])
        ->and($users->columns['id']->autoIncrement)->toBeTrue()
        ->and($users->columns['email']->nullable)->toBeFalse()
        ->and($users->columns['email']->default)->toBe("'user@example.com'")
        ->and($users->indexes)->toHaveKey('constraint:email')
        ->and($users->indexes['constraint:email']->unique)->toBeTrue()
        ->and($users->indexes['constraint:email']->source)->toBe('constraint')
        ->and($users->indexes)->toHaveKey('idx_users_score')
        ->and($users->indexes['idx_users_score']->partial)->toBeTrue()
        ->and($users->indexes['idx_users_score']->sql)->toContain('WHERE score > 0');

    expect(array_keys($users->foreignKeys))->toBe(['fk:team_id->teams(id)'])
        ->and($users->foreignKeys['fk:team_id->teams(id)']->onDelete)->toBe('CASCADE')
        ->and($state->views['active_users']->sql)->toContain('CREATE VIEW active_users');
});


test('reports sqlite version', function () {
    $pdo = SchemaFixtures::sqlite(sys_get_temp_dir() .'/schema-version-'. bin2hex(random_bytes(6)) .'.sqlite');

    expect((new SqliteDriver())->version($pdo))->toStartWith('3.');
});


test('detects triggers as unsupported objects', function () {
    $path = sys_get_temp_dir() .'/schema-trigger-'. bin2hex(random_bytes(6)) .'.sqlite';
    $pdo = SchemaFixtures::sqlite($path, <<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);
CREATE TABLE audit (id INTEGER PRIMARY KEY, user_id INTEGER);
CREATE TRIGGER trg_users_audit AFTER INSERT ON users
BEGIN
    INSERT INTO audit (user_id) VALUES (NEW.id);
END;
SQL);

    expect((new SqliteDriver())->unsupportedObjects($pdo))
        ->toBe(['trigger trg_users_audit']);
});
