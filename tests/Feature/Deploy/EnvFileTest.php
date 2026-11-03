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


test('rejects an env symlink', function () {
    $root = deployEnvWorkspace('staging', ['environment' => 'staging']);
    $link = $root .'/link.json';
    symlink(EnvFile::path($root, 'staging'), $link);

    expect(fn () => EnvFile::load($link, 'staging'))
        ->toThrow(RuntimeException::class, 'cannot be a symlink');
});


test('rejects an env larger than the limit', function () {
    $root = deployEnvWorkspace('staging', ['environment' => 'staging']);
    $file = EnvFile::path($root, 'staging');
    file_put_contents($file, str_repeat('a', 65 * 1024));

    expect(fn () => EnvFile::load($file, 'staging'))
        ->toThrow(RuntimeException::class, 'exceeds');
});


test('rejects invalid env json', function () {
    $root = deployEnvWorkspace('staging', ['environment' => 'staging']);
    $file = EnvFile::path($root, 'staging');
    file_put_contents($file, '{');

    expect(fn () => EnvFile::load($file, 'staging'))
        ->toThrow(RuntimeException::class, 'invalid local env');
});


test('rejects an env that is not a json object', function () {
    $root = deployEnvWorkspace('staging', ['environment' => 'staging']);
    $file = EnvFile::path($root, 'staging');
    file_put_contents($file, '"text"');

    expect(fn () => EnvFile::load($file, 'staging'))
        ->toThrow(RuntimeException::class, 'must be a JSON object');
});


test('rejects an unreadable env', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('permission checks require a non-root user');
    }

    $root = deployEnvWorkspace('staging', ['environment' => 'staging']);
    $file = EnvFile::path($root, 'staging');
    chmod($file, 0000);

    try {
        expect(fn () => EnvFile::load($file, 'staging'))
            ->toThrow(RuntimeException::class, 'not found');
    } finally {
        chmod($file, 0600);
    }
});


test('hash is stable regardless of key order', function () {
    $left = ['environment' => 'staging', 'database' => ['host' => 'db', 'port' => 3306]];
    $right = ['database' => ['port' => 3306, 'host' => 'db'], 'environment' => 'staging'];

    expect(EnvFile::hash($left))->toBe(EnvFile::hash($right));
});


test('hash preserves list order', function () {
    expect(EnvFile::hash(['features' => ['a', 'b']]))
        ->toBe(EnvFile::hash(['features' => ['a', 'b']]))
        ->not->toBe(EnvFile::hash(['features' => ['b', 'a']]));
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
