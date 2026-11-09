<?php declare(strict_types=1);

use Sourcerer\Tool\Code\Initializer;


function deployInitializerRoot(): string
{
    $root = sys_get_temp_dir() .'/code-install-'. bin2hex(random_bytes(6));

    mkdir($root, 0700, true);
    file_put_contents($root .'/example.json', '{"global":{},"targets":{}}');

    return $root;
}


function deployInitializer(string $root, ?string $example = null): Initializer
{
    return new Initializer($root, $example ?? $root .'/example.json');
}


test('creates the local code layout and copies the example config', function () {
    $root = deployInitializerRoot();
    $lines = deployInitializer($root)->run();

    expect(is_dir($root .'/.sourcerer/code/ssh'))->toBeTrue()
        ->and(is_dir($root .'/.sourcerer/code/override/development/public'))->toBeTrue()
        ->and(is_dir($root .'/.sourcerer/code/override/staging/application'))->toBeTrue()
        ->and(is_dir($root .'/.sourcerer/code/override/production/public'))->toBeTrue()
        ->and(is_dir($root .'/.sourcerer/code/override/production/vendor'))->toBeFalse()
        ->and(is_dir($root .'/.sourcerer/code/backups'))->toBeFalse()
        ->and(is_dir($root .'/.sourcerer/code/lock'))->toBeFalse()
        ->and(file_get_contents($root .'/.sourcerer/code/config.json'))->toBe('{"global":{},"targets":{}}')
        ->and($lines)->toContain('created .sourcerer/code');
});


test('does not overwrite an existing code config', function () {
    $root = deployInitializerRoot();
    mkdir($root .'/.sourcerer/code', 0700, true);
    file_put_contents($root .'/.sourcerer/code/config.json', '{"custom":true}');

    deployInitializer($root)->run();

    expect(file_get_contents($root .'/.sourcerer/code/config.json'))->toBe('{"custom":true}');
});


test('is idempotent', function () {
    $root = deployInitializerRoot();
    $initializer = deployInitializer($root);

    $initializer->run();
    $initializer->run();

    expect(is_dir($root .'/.sourcerer/code/ssh'))->toBeTrue()
        ->and(is_dir($root .'/.sourcerer/code/override/staging/public'))->toBeTrue()
        ->and(file_get_contents($root .'/.sourcerer/code/config.json'))->toBe('{"global":{},"targets":{}}');
});


test('rejects a code directory that is a file', function () {
    $root = deployInitializerRoot();
    file_put_contents($root .'/.sourcerer', 'not a directory');

    expect(fn () => deployInitializer($root)->run())
        ->toThrow(RuntimeException::class, 'could not create directory');
});


test('rejects a code config symlink', function () {
    $root = deployInitializerRoot();
    mkdir($root .'/.sourcerer/code', 0700, true);
    $target = $root .'/real.json';
    file_put_contents($target, '{}');
    symlink($target, $root .'/.sourcerer/code/config.json');

    expect(fn () => deployInitializer($root)->run())
        ->toThrow(RuntimeException::class, 'refusing to use symlink');
});


test('rejects a symlinked code subdirectory', function () {
    $root = deployInitializerRoot();
    mkdir($root .'/.sourcerer/code', 0700, true);
    mkdir($root .'/elsewhere', 0700);
    symlink($root .'/elsewhere', $root .'/.sourcerer/code/ssh');

    expect(fn () => deployInitializer($root)->run())
        ->toThrow(RuntimeException::class, 'refusing to use symlink');
});


test('fails when the example config is missing', function () {
    $root = deployInitializerRoot();

    expect(fn () => deployInitializer($root, $root .'/missing.json')->run())
        ->toThrow(RuntimeException::class, 'missing file');
});


test('creates the redirect stubs', function () {
    $root = deployInitializerRoot();

    deployInitializer($root)->run();

    expect(file_get_contents($root .'/.sourcerer/code/stub/index.php'))
        ->toContain('header(\'Location: %url%\')')
        ->and(file_get_contents($root .'/.sourcerer/code/stub/.htaccess'))
        ->toContain('RewriteRule ^(.*)$ index.php [L,QSA]')
        ->and(is_dir($root .'/.sourcerer/code/releases'))->toBeTrue();
});


test('does not overwrite customized redirect stubs', function () {
    $root = deployInitializerRoot();
    mkdir($root .'/.sourcerer/code/stub', 0700, true);
    file_put_contents($root .'/.sourcerer/code/stub/index.php', 'custom-index');
    file_put_contents($root .'/.sourcerer/code/stub/.htaccess', 'custom-htaccess');

    deployInitializer($root)->run();

    expect(file_get_contents($root .'/.sourcerer/code/stub/index.php'))->toBe('custom-index')
        ->and(file_get_contents($root .'/.sourcerer/code/stub/.htaccess'))->toBe('custom-htaccess');
});
