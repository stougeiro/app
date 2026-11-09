<?php declare(strict_types=1);

use Sourcerer\Tool\Schema\Config;
use Tests\Feature\Schema\SchemaFixtures;


function schemaConfigFile(array $data): string
{
    $root = SchemaFixtures::workspace();
    file_put_contents($root .'/.sourcerer/schema/config.json', json_encode($data, JSON_THROW_ON_ERROR));

    return $root;
}


test('loads database and local target', function () {
    $config = Config::load(SchemaFixtures::workspace());

    expect($config->names())->toBe(['default'])
        ->and($config->database('default')->driver)->toBe('sqlite')
        ->and($config->database('default')->local()->transport)->toBe('local')
        ->and($config->database('default')->local()->database)->toBe('storage/database.sqlite');
});


test('rejects missing schema config', function () {
    $root = sys_get_temp_dir() .'/schema-missing-'. bin2hex(random_bytes(6));
    mkdir($root, 0755, true);

    expect(fn () => Config::load($root))
        ->toThrow(RuntimeException::class, '.sourcerer/schema/config.json not found');
});


test('rejects unknown database', function () {
    $config = Config::load(SchemaFixtures::workspace());

    expect(fn () => $config->database('analytics'))
        ->toThrow(RuntimeException::class, "unknown database 'analytics'");
});


test('rejects invalid driver', function () {
    $data = SchemaFixtures::config();
    $data['databases']['default']['driver'] = 'oracle';
    $root = schemaConfigFile($data);

    expect(fn () => Config::load($root))
        ->toThrow(RuntimeException::class, "invalid driver 'oracle'");
});


test('rejects a database without local target', function () {
    $data = SchemaFixtures::config();
    $data['databases']['default']['targets']['development'] = [
        'transport' => 'local',
        'database' => 'storage/development.sqlite',
    ];
    unset($data['databases']['default']['targets']['local']);
    $root = schemaConfigFile($data);

    expect(fn () => Config::load($root))
        ->toThrow(RuntimeException::class, "requires a 'local' target");
});


test('requires ssh php binary', function () {
    $data = SchemaFixtures::config();
    $data['databases']['default']['targets']['development'] = [
        'transport' => 'ssh',
        'database' => '/srv/dev/database.sqlite',
        'ssh' => [
            'host' => 'example.com',
            'user' => 'deploy',
        ],
    ];
    $root = schemaConfigFile($data);

    expect(fn () => Config::load($root))
        ->toThrow(RuntimeException::class, "'php_binary' must be a non-empty string");
});


test('rejects target driver override', function () {
    $data = SchemaFixtures::config();
    $data['databases']['default']['targets']['local']['driver'] = 'mysql';
    $root = schemaConfigFile($data);

    expect(fn () => Config::load($root))
        ->toThrow(RuntimeException::class, 'must not override the database driver');
});
