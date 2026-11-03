<?php declare(strict_types=1);

use App\Core\Log\ClassifiedLog;
use Sourcerer\Log\Log;

beforeEach(function () {
    $this->errorFile = tempnam(sys_get_temp_dir(), 'error_log_');
    $this->securityFile = tempnam(sys_get_temp_dir(), 'security_log_');
    $this->logger = new ClassifiedLog(
        new Log($this->errorFile),
        new Log($this->securityFile),
    );
});

afterEach(function () {
    @unlink($this->errorFile);
    @unlink($this->securityFile);
});

test('4xx logs go to the security log as warnings', function () {
    $this->logger->error('Not Found', [
        'status' => 404,
        'path' => '/missing',
        'ip' => '203.0.113.7',
        'referer' => 'http://localhost/',
    ]);

    expect(file_get_contents($this->securityFile))
        ->toContain('WARN')
        ->toContain('Not Found')
        ->toContain('"status":404')
        ->toContain('"path":"/missing"')
        ->toContain('"referer":"http://localhost/"')
        ->and(file_get_contents($this->errorFile))->toBe('');
});

test('5xx logs go to the error log', function () {
    $this->logger->error('Boom', [
        'status' => 500,
        'path' => '/boom',
    ]);

    expect(file_get_contents($this->errorFile))
        ->toContain('ERROR')
        ->toContain('Boom')
        ->toContain('"status":500')
        ->and(file_get_contents($this->securityFile))->toBe('');
});

test('2xx status is not treated as a security event', function () {
    $this->logger->error('OK', [
        'status' => 200,
        'path' => '/',
    ]);

    expect(file_get_contents($this->errorFile))
        ->toContain('OK')
        ->and(file_get_contents($this->securityFile))->toBe('');
});

test('warn info and debug always use the error log', function () {
    $this->logger->warn('Warned');
    $this->logger->info('Informed');
    $this->logger->debug('Debugged');

    expect(file_get_contents($this->errorFile))
        ->toContain('WARN')
        ->toContain('Warned')
        ->toContain('INFO')
        ->toContain('Informed')
        ->toContain('DEBUG')
        ->toContain('Debugged')
        ->and(file_get_contents($this->securityFile))->toBe('');
});

test('status boundaries choose the correct log', function (int $status, string $log, string $level) {
    $this->logger->error('Boundary', ['status' => $status]);

    $file = $log === 'security' ? $this->securityFile : $this->errorFile;
    $other = $log === 'security' ? $this->errorFile : $this->securityFile;

    expect(file_get_contents($file))
        ->toContain($level)
        ->toContain('"status":'. $status)
        ->and(file_get_contents($other))->toBe('');
})->with([
    [399, 'error', 'ERROR'],
    [400, 'security', 'WARN'],
    [499, 'security', 'WARN'],
    [500, 'error', 'ERROR'],
]);

test('numeric string status is treated as a security event', function () {
    $this->logger->error('Missing', ['status' => '404']);

    expect(file_get_contents($this->securityFile))
        ->toContain('WARN')
        ->toContain('"status":"404"')
        ->and(file_get_contents($this->errorFile))->toBe('');
});

test('non-string host and referer become empty strings', function () {
    $previousHost = $_SERVER['HTTP_HOST'] ?? null;
    $previousReferer = $_SERVER['HTTP_REFERER'] ?? null;

    $_SERVER['HTTP_HOST'] = ['invalid'];
    $_SERVER['HTTP_REFERER'] = ['invalid'];

    try {
        $this->logger->error('Boom', ['status' => 500]);

        expect(file_get_contents($this->errorFile))
            ->toContain('"host":""')
            ->toContain('"referer":""');
    } finally {
        if ($previousHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $previousHost;
        }

        if ($previousReferer === null) {
            unset($_SERVER['HTTP_REFERER']);
        } else {
            $_SERVER['HTTP_REFERER'] = $previousReferer;
        }
    }
});

test('log context includes host and referer from the request', function () {
    $previousHost = $_SERVER['HTTP_HOST'] ?? null;
    $previousReferer = $_SERVER['HTTP_REFERER'] ?? null;

    $_SERVER['HTTP_HOST'] = 'example.test';
    $_SERVER['HTTP_REFERER'] = 'https://example.test/previous';

    try {
        $this->logger->error('Forbidden', ['status' => 403]);

        expect(file_get_contents($this->securityFile))
            ->toContain('"host":"example.test"')
            ->toContain('"referer":"https://example.test/previous"');
    } finally {
        if ($previousHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $previousHost;
        }

        if ($previousReferer === null) {
            unset($_SERVER['HTTP_REFERER']);
        } else {
            $_SERVER['HTTP_REFERER'] = $previousReferer;
        }
    }
});
