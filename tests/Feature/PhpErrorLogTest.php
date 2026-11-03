<?php declare(strict_types=1);

use App\Core\Log\PhpErrorLog;

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

test('does not configure a directory without write permission', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('permission checks require a non-root user');
    }

    $directory = sys_get_temp_dir() . '/php_error_ro_'. uniqid();
    mkdir($directory, 0500);
    $path = $directory .'/php-error.log';

    try {
        expect(PhpErrorLog::configure($path))->toBeFalse();
    } finally {
        chmod($directory, 0700);
        rmdir($directory);
    }
});

test('does not configure an existing file without write permission', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('permission checks require a non-root user');
    }

    $directory = sys_get_temp_dir() . '/php_error_file_'. uniqid();
    mkdir($directory);
    $path = $directory .'/php-error.log';
    touch($path);
    chmod($path, 0444);

    try {
        expect(PhpErrorLog::configure($path))->toBeFalse();
    } finally {
        chmod($path, 0644);
        unlink($path);
        rmdir($directory);
    }
});

test('keeps an already configured error log unchanged', function () {
    $directory = sys_get_temp_dir() . '/php_error_existing_'. uniqid();
    mkdir($directory);
    $path = $directory .'/php-error.log';
    $previous = ini_get('error_log');

    try {
        ini_set('error_log', $path);

        expect(PhpErrorLog::configure($path))->toBeTrue()
            ->and(ini_get('error_log'))->toBe($path);
    } finally {
        if (is_string($previous)) {
            ini_set('error_log', $previous);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});
