<?php declare(strict_types=1);

use App\Core\Log\PhpErrorLog;
use Tests\Fixtures\PhpErrorLogTestable;

beforeEach(function () {
    PhpErrorLogTestable::reset();
});

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
    $directory = sys_get_temp_dir() . '/php_error_ro_'. uniqid();
    $path = $directory .'/php-error.log';

    PhpErrorLogTestable::denyWritable($directory);

    expect(PhpErrorLogTestable::configure($path))->toBeFalse();
});

test('does not configure an existing file without write permission', function () {
    $directory = sys_get_temp_dir() . '/php_error_file_'. uniqid();
    $path = $directory .'/php-error.log';

    PhpErrorLogTestable::allowFile($path);
    PhpErrorLogTestable::denyWritable($path);

    expect(PhpErrorLogTestable::configure($path))->toBeFalse();
});

test('does not configure when ini_set is unavailable', function () {
    PhpErrorLogTestable::disableFunction('ini_set');

    expect(PhpErrorLogTestable::configure('/tmp/php-error.log'))->toBeFalse();
});

test('does not configure when ini_set is disabled', function () {
    PhpErrorLogTestable::setIni('disable_functions', 'ini_set');

    expect(PhpErrorLogTestable::configure('/tmp/php-error.log'))->toBeFalse();
});

test('does not configure when ini_set fails', function () {
    PhpErrorLogTestable::failIniSet('error_log');

    expect(PhpErrorLogTestable::configure('/tmp/php-error.log'))->toBeFalse();
});

test('restores the previous error_log when ini_set does not stick', function () {
    PhpErrorLogTestable::setIni('error_log', '/tmp/previous.log');
    PhpErrorLogTestable::iniSetReturns('error_log', '/tmp/previous.log');

    expect(PhpErrorLogTestable::configure('/tmp/php-error.log'))->toBeFalse()
        ->and(PhpErrorLogTestable::$iniSetCalls)->toHaveCount(2)
        ->and(PhpErrorLogTestable::$iniSetCalls[1])->toBe([
            'name' => 'error_log',
            'value' => '/tmp/previous.log',
        ]);
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
