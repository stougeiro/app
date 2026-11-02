<?php declare(strict_types=1);

use App\Log\PhpErrorLog;

test('configures a daily php error log when the directory is writable', function () {
    $directory = sys_get_temp_dir() . '/php_error_log_'. uniqid();
    mkdir($directory);
    $path = $directory .'/php-error-20261102.log';
    $previous = ini_get('error_log');

    try {
        expect(PhpErrorLog::configure($path))->toBeTrue()
            ->and(ini_get('error_log'))->toBe($path);
    } finally {
        if (is_string($previous)) {
            ini_set('error_log', $previous);
        }

        if (is_file($path)) {
            unlink($path);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

test('does not configure a missing directory', function () {
    $path = sys_get_temp_dir() . '/missing_'. uniqid() .'/php-error.log';

    expect(PhpErrorLog::configure($path))->toBeFalse();
});
