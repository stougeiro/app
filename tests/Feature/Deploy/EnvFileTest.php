<?php declare(strict_types=1);

use Deploy\EnvFile;



function deployEnvWorkspace(string $environment, array $data): string
{
    $root = sys_get_temp_dir() .'/deploy-env-'. bin2hex(random_bytes(6));
    $directory = $root .'/.deploy/env';

    mkdir($directory, 0700, true);
    file_put_contents($directory .'/'. $environment .'.json', json_encode($data, JSON_THROW_ON_ERROR));

    return $root;
}


test('builds the env path per environment', function () {
    expect(EnvFile::path('/app', 'production'))->toBe('/app/.deploy/env/production.json');
});


test('loads env whose environment matches the target', function () {
    $root = deployEnvWorkspace('staging', [
        'environment' => 'staging',
        'database' => ['host' => 'db'],
    ]);

    $data = EnvFile::load(EnvFile::path($root, 'staging'), 'staging');

    expect($data['environment'])->toBe('staging')
        ->and($data['database'])->toBe(['host' => 'db']);
});


test('rejects env with a mismatched environment', function () {
    $root = deployEnvWorkspace('staging', ['environment' => 'production']);

    expect(fn () => EnvFile::load(EnvFile::path($root, 'staging'), 'staging'))
        ->toThrow(RuntimeException::class, "expected 'staging'");
});


test('rejects a missing env', function () {
    expect(fn () => EnvFile::load('/tmp/nao-existe-env.json', 'staging'))
        ->toThrow(RuntimeException::class, 'not found');
});


test('hash is stable regardless of key order', function () {
    $left = ['environment' => 'staging', 'database' => ['host' => 'db', 'port' => 3306]];
    $right = ['database' => ['port' => 3306, 'host' => 'db'], 'environment' => 'staging'];

    expect(EnvFile::hash($left))->toBe(EnvFile::hash($right));
});


test('diff reports added, changed and removed keys', function () {
    $diff = EnvFile::diff(
        ['environment' => 'staging', 'database' => ['host' => 'novo', 'port' => 3306], 'cache' => 'redis'],
        ['environment' => 'staging', 'database' => ['host' => 'velho'], 'mail' => 'smtp'],
    );

    expect($diff)->toBe([
        'added' => ['cache', 'database.port'],
        'changed' => ['database.host'],
        'removed' => ['mail'],
    ]);
});


test('diff against a missing remote env lists all keys', function () {
    $diff = EnvFile::diff(['environment' => 'staging', 'database' => ['host' => 'db']], null);

    expect($diff)->toBe([
        'added' => ['environment', 'database.host'],
        'changed' => [],
        'removed' => [],
    ]);
});
