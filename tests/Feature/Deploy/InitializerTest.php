<?php declare(strict_types=1);

use Deploy\Initializer;


function deployInitializerRoot(): string
{
    $root = sys_get_temp_dir() .'/deploy-init-'. bin2hex(random_bytes(6));

    mkdir($root .'/deploy', 0700, true);
    file_put_contents($root .'/deploy/deploy.example.json', '{"targets":{}}');

    return $root;
}


test('creates the local .deploy directories and copies the example config', function () {
    $root = deployInitializerRoot();

    (new Initializer($root))->run();

    expect(is_dir($root .'/.deploy/env'))->toBeTrue()
        ->and(is_dir($root .'/.deploy/ssh'))->toBeTrue()
        ->and(is_dir($root .'/.deploy/plans'))->toBeTrue()
        ->and(is_dir($root .'/.deploy/override/development/public_html'))->toBeTrue()
        ->and(is_dir($root .'/.deploy/override/staging/application'))->toBeTrue()
        ->and(is_dir($root .'/.deploy/override/production/vendor'))->toBeTrue()
        ->and(file_get_contents($root .'/deploy.json'))->toBe('{"targets":{}}');
});


test('does not overwrite an existing deploy config', function () {
    $root = deployInitializerRoot();
    file_put_contents($root .'/deploy.json', '{"custom":true}');

    (new Initializer($root))->run();

    expect(file_get_contents($root .'/deploy.json'))->toBe('{"custom":true}');
});


test('is idempotent', function () {
    $root = deployInitializerRoot();
    $initializer = new Initializer($root);

    $initializer->run();
    $initializer->run();

    expect(is_dir($root .'/.deploy/env'))->toBeTrue()
        ->and(is_dir($root .'/.deploy/ssh'))->toBeTrue()
        ->and(is_dir($root .'/.deploy/plans'))->toBeTrue()
        ->and(is_dir($root .'/.deploy/override/staging/public_html'))->toBeTrue()
        ->and(file_get_contents($root .'/deploy.json'))->toBe('{"targets":{}}');
});


test('rejects .deploy when it is not a directory', function () {
    $root = deployInitializerRoot();
    file_put_contents($root .'/.deploy', 'not a directory');

    expect(fn () => (new Initializer($root))->run())
        ->toThrow(RuntimeException::class, 'not a directory');
});


test('fails when the example config is missing', function () {
    $root = sys_get_temp_dir() .'/deploy-init-'. bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    expect(fn () => (new Initializer($root))->run())
        ->toThrow(RuntimeException::class, 'example not found');
});
