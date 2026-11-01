<?php declare(strict_types=1);

use Sourcerer\Http\Kernel;
use Tests\Fixtures\FakeRequest;

beforeEach(function () {
    $this->kernel = Kernel::create();
});

test('error page renders through the app view layer', function () {
    $response = $this->kernel->handle(new FakeRequest('GET', 'http://localhost/missing'));

    expect($response->getStatus())->toBe(404)
        ->and($response->getBody())->toContain('Página não encontrada')
        ->and($response->getBody())->toContain('O recurso solicitado não existe.');
});

test('error page keeps json negotiation', function () {
    $response = $this->kernel->handle(new FakeRequest(
        'GET',
        'http://localhost/missing',
        ['Accept' => 'application/json'],
    ));

    $decoded = json_decode((string) $response->getBody(), true);

    expect($response->getHeader('Content-Type'))->toContain('application/json')
        ->and($response->getStatus())->toBe(404)
        ->and($decoded['status'])->toBe('error')
        ->and($decoded['code'])->toBe(404);
});
