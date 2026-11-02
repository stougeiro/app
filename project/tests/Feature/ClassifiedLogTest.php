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
