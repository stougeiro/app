<?php declare(strict_types=1);

use Sourcerer\Tool\Contract\ExitCode;
use Sourcerer\Tool\Schema\Command\InitCommand;
use Sourcerer\Tool\Schema\Command\StatusCommand;
use Sourcerer\Tool\Schema\Exception\UnsupportedObjectsException;
use Tests\Feature\Schema\SchemaFixtures;


function schemaWorkspaceWithDatabase(string $sql = ''): string
{
    $root = SchemaFixtures::workspace();
    SchemaFixtures::sqlite($root .'/storage/database.sqlite', $sql);

    return $root;
}


test('init creates genesis, release sql, checkpoint and head', function () {
    $root = schemaWorkspaceWithDatabase(<<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE);
CREATE VIEW active_users AS SELECT * FROM users;
SQL);
    $command = new InitCommand($root);
    $fixture = SchemaFixtures::output();

    $code = $command->run(SchemaFixtures::input($command, []), $fixture['output']);

    expect($code)->toBe(ExitCode::Success->value)
        ->and(is_file($root .'/schema/default/HEAD'))->toBeTrue()
        ->and(is_file($root .'/schema/default/genesis/genesis.json'))->toBeTrue()
        ->and(is_file($root .'/schema/default/genesis/schema.json'))->toBeTrue()
        ->and(is_file($root .'/schema/default/genesis/release.sql'))->toBeTrue()
        ->and(is_file($root .'/schema/default/checkpoints/GENESIS.json'))->toBeTrue()
        ->and(trim((string) file_get_contents($root .'/schema/default/HEAD')))->toBe('GENESIS')
        ->and(SchemaFixtures::read($fixture['stream']))->toContain('genesis created');
});


test('init refuses an already initialized database', function () {
    $root = schemaWorkspaceWithDatabase('CREATE TABLE users (id INTEGER PRIMARY KEY);');
    $command = new InitCommand($root);

    $command->run(SchemaFixtures::input($command, []), SchemaFixtures::output()['output']);
    $code = $command->run(SchemaFixtures::input($command, []), SchemaFixtures::output()['output']);

    expect($code)->toBe(ExitCode::Failure->value);
});


test('init refuses databases with triggers', function () {
    $root = schemaWorkspaceWithDatabase(<<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY);
CREATE TABLE audit (id INTEGER PRIMARY KEY, user_id INTEGER);
CREATE TRIGGER trg_users_audit AFTER INSERT ON users
BEGIN
    INSERT INTO audit (user_id) VALUES (NEW.id);
END;
SQL);
    $command = new InitCommand($root);

    expect(fn () => $command->run(SchemaFixtures::input($command, []), SchemaFixtures::output()['output']))
        ->toThrow(UnsupportedObjectsException::class, 'trigger trg_users_audit');
});


test('status reports local state and target state', function () {
    $root = schemaWorkspaceWithDatabase('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT);');
    $init = new InitCommand($root);
    $init->run(SchemaFixtures::input($init, []), SchemaFixtures::output()['output']);

    $status = new StatusCommand($root);

    $local = SchemaFixtures::output();
    expect($status->run(SchemaFixtures::input($status, []), $local['output']))
        ->toBe(ExitCode::Success->value)
        ->and(SchemaFixtures::read($local['stream']))->toContain('local: OK');

    $target = SchemaFixtures::output();
    expect($status->run(SchemaFixtures::input($status, ['--target=local']), $target['output']))
        ->toBe(ExitCode::Success->value)
        ->and(SchemaFixtures::read($target['stream']))->toContain('state: CLEAN');
});


test('status reports drift when the local database changes', function () {
    $root = schemaWorkspaceWithDatabase('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT);');
    $init = new InitCommand($root);
    $init->run(SchemaFixtures::input($init, []), SchemaFixtures::output()['output']);

    $pdo = new PDO('sqlite:'. $root .'/storage/database.sqlite');
    $pdo->exec('CREATE TABLE extra (id INTEGER PRIMARY KEY)');

    $status = new StatusCommand($root);
    $fixture = SchemaFixtures::output();

    expect($status->run(SchemaFixtures::input($status, ['--target=local']), $fixture['output']))
        ->toBe(ExitCode::Success->value)
        ->and(SchemaFixtures::read($fixture['stream']))->toContain('state: DRIFT');
});


test('schema tool registers its commands', function () {
    $tool = new \Sourcerer\Tool\Schema\SchemaTool();

    expect(array_keys($tool->commands(sys_get_temp_dir())))->toBe([
        'schema init',
        'schema status',
    ])->and($tool->name())->toBe('schema');
});
