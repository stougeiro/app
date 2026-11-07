<?php declare(strict_types=1);

use Tests\Feature\Deploy\DeployFixtures;
use Tool\Deploy\Initializer;


function deployInitializerRoot(): string
{
    $root = sys_get_temp_dir() .'/deploy-init-'. bin2hex(random_bytes(6));

    mkdir($root .'/tools/deploy', 0700, true);
    file_put_contents($root .'/tools/deploy/deploy.example.json', '{"targets":{}}');

    return $root;
}


test('creates the local deploy directories and copies the example config', function () {
    $root = deployInitializerRoot();

    (new Initializer($root, DeployFixtures::output()))->run();

    expect(is_dir($root .'/.deploy/ssh'))->toBeTrue()
        ->and(is_dir($root .'/.deploy/override/development/public'))->toBeTrue()
        ->and(is_dir($root .'/.deploy/override/staging/application'))->toBeTrue()
        ->and(is_dir($root .'/.deploy/override/production/public'))->toBeTrue()
        ->and(is_dir($root .'/.deploy/override/production/vendor'))->toBeFalse()
        ->and(file_get_contents($root .'/deploy.json'))->toBe('{"targets":{}}');
});


test('does not overwrite an existing deploy config', function () {
    $root = deployInitializerRoot();
    file_put_contents($root .'/deploy.json', '{"custom":true}');

    (new Initializer($root, DeployFixtures::output()))->run();

    expect(file_get_contents($root .'/deploy.json'))->toBe('{"custom":true}');
});


test('is idempotent', function () {
    $root = deployInitializerRoot();
    $initializer = new Initializer($root, DeployFixtures::output());

    $initializer->run();
    $initializer->run();

    expect(is_dir($root .'/.deploy/ssh'))->toBeTrue()
        ->and(is_dir($root .'/.deploy/override/staging/public'))->toBeTrue()
        ->and(file_get_contents($root .'/deploy.json'))->toBe('{"targets":{}}');
});


test('rejects .deploy when it is not a directory', function () {
    $root = deployInitializerRoot();
    file_put_contents($root .'/.deploy', 'not a directory');

    expect(fn () => (new Initializer($root, DeployFixtures::output()))->run())
        ->toThrow(RuntimeException::class, 'not a directory');
});


test('rejects a deploy config symlink', function () {
    $root = deployInitializerRoot();
    $target = $root .'/tools/deploy/real.json';
    file_put_contents($target, '{}');
    symlink($target, $root .'/deploy.json');

    expect(fn () => (new Initializer($root, DeployFixtures::output()))->run())
        ->toThrow(RuntimeException::class, 'cannot be a symlink');
});


test('rejects a deploy config that is not a regular file', function () {
    $root = deployInitializerRoot();
    mkdir($root .'/deploy.json');

    expect(fn () => (new Initializer($root, DeployFixtures::output()))->run())
        ->toThrow(RuntimeException::class, 'not a regular file');
});


test('rejects a symlinked deploy subdirectory', function () {
    $root = deployInitializerRoot();
    mkdir($root .'/.deploy', 0700);
    mkdir($root .'/elsewhere', 0700);
    symlink($root .'/elsewhere', $root .'/.deploy/ssh');

    expect(fn () => (new Initializer($root, DeployFixtures::output()))->run())
        ->toThrow(RuntimeException::class, 'refusing to use symlink');
});


test('rejects a deploy subdirectory that is a file', function () {
    $root = deployInitializerRoot();
    mkdir($root .'/.deploy', 0700);
    file_put_contents($root .'/.deploy/ssh', 'not a directory');

    expect(fn () => (new Initializer($root, DeployFixtures::output()))->run())
        ->toThrow(RuntimeException::class, 'not a directory');
});


test('fails when the example config is missing', function () {
    $root = sys_get_temp_dir() .'/deploy-init-'. bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    expect(fn () => (new Initializer($root, DeployFixtures::output()))->run())
        ->toThrow(RuntimeException::class, 'example not found');
});


test('creates the redirect stubs', function () {
    $root = deployInitializerRoot();

    (new Initializer($root, DeployFixtures::output()))->run();

    expect(file_get_contents($root .'/.deploy/stub/index.php'))
        ->toContain('header(\'Location: %url%\')')
        ->and(file_get_contents($root .'/.deploy/stub/.htaccess'))
        ->toContain('RewriteRule ^(.*)$ index.php [L,QSA]')
        ->and(is_dir($root .'/.deploy/releases'))->toBeTrue()
        ->and(is_dir($root .'/.deploy/targets'))->toBeTrue();
});


test('does not overwrite customized redirect stubs', function () {
    $root = deployInitializerRoot();
    mkdir($root .'/.deploy/stub', 0700, true);
    file_put_contents($root .'/.deploy/stub/index.php', 'custom-index');
    file_put_contents($root .'/.deploy/stub/.htaccess', 'custom-htaccess');

    (new Initializer($root, DeployFixtures::output()))->run();

    expect(file_get_contents($root .'/.deploy/stub/index.php'))->toBe('custom-index')
        ->and(file_get_contents($root .'/.deploy/stub/.htaccess'))->toBe('custom-htaccess');
});
