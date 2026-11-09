<?php declare(strict_types=1);

use Sourcerer\Tool\Support\FileSystem;
use Sourcerer\Tool\Code\Support\Paths;


test('override resolves the local target tree', function () {
    expect(Paths::override('/srv/app/', 'staging', 'public'))
        ->toBe('/srv/app/.sourcerer/code/override/staging/public');
});


test('copy dir replicates files and skips excluded top-level entries', function () {
    $root = sys_get_temp_dir() .'/code-support-'. bin2hex(random_bytes(6));
    $source = $root .'/source';
    $destination = $root .'/destination';

    mkdir($source .'/keep', 0700, true);
    mkdir($source .'/storage', 0700, true);
    file_put_contents($source .'/root.txt', 'root');
    file_put_contents($source .'/keep/file.txt', 'keep');
    file_put_contents($source .'/storage/skip.txt', 'skip');

    FileSystem::copyDir($source, $destination, ['storage']);

    expect(file_get_contents($destination .'/root.txt'))->toBe('root')
        ->and(file_get_contents($destination .'/keep/file.txt'))->toBe('keep')
        ->and(is_file($destination .'/storage/skip.txt'))->toBeFalse();

    FileSystem::removeDir($root);

    expect(is_dir($root))->toBeFalse();
});


test('temporary archive has the expected extension and location', function () {
    $archive = FileSystem::temporaryArchive('code-test-');

    expect($archive)->toStartWith(sys_get_temp_dir() .'/code-test-')
        ->and($archive)->toEndWith('.tar.gz')
        ->and(is_file($archive))->toBeFalse();
});
