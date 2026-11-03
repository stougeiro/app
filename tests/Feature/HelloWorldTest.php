<?php declare(strict_types=1);

use Sourcerer\Http\Kernel;
use Tests\Fixtures\FakeRequest;

beforeEach(function () {
    $this->kernel = Kernel::create();
});

test('hello world module renders its view', function () {
    $response = $this->kernel->handle(new FakeRequest('GET', 'http://localhost/'));

    expect($response->getStatus())->toBe(200)
        ->and($response->getBody())->toContain('Hello World')
        ->and($response->getHeader('X-Chain'))->toBe('module controller');
});

test('hello world module answers options', function () {
    $response = $this->kernel->handle(new FakeRequest('OPTIONS', 'http://localhost/'));

    expect($response->getStatus())->toBe(204)
        ->and($response->getHeader('Allow'))->toBe('GET, OPTIONS');
});

test('unknown route returns 404', function () {
    $response = $this->kernel->handle(new FakeRequest('GET', 'http://localhost/missing'));

    expect($response->getStatus())->toBe(404);
});
