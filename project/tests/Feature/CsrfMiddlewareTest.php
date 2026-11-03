<?php declare(strict_types=1);

use App\Core\Security\Csrf;
use App\Core\Security\Middleware\CsrfMiddleware;
use Sourcerer\Contract\Error\HttpException;
use Tests\Fixtures\ArraySession;
use Tests\Fixtures\FakeRequest;

test('csrf middleware ignores safe methods', function () {
    $middleware = new CsrfMiddleware(new Csrf(new ArraySession()));

    $response = $middleware->process(
        new FakeRequest('GET', 'http://localhost/'),
        new \STDW\Http\Response(),
        static fn ($request, $response) => $response,
    );

    expect($response->getStatus())->toBe(200);
});

test('csrf middleware rejects an unsafe request without a token', function () {
    $middleware = new CsrfMiddleware(new Csrf(new ArraySession()));

    expect(fn () => $middleware->process(
        new FakeRequest('POST', 'http://localhost/'),
        new \STDW\Http\Response(),
        static fn ($request, $response) => $response,
    ))->toThrow(HttpException::class);
});

test('csrf middleware accepts an unsafe request with a valid token', function () {
    $csrf = new Csrf(new ArraySession());
    $token = $csrf->token();
    $middleware = new CsrfMiddleware($csrf);

    $response = $middleware->process(
        new FakeRequest('POST', 'http://localhost/', ['X-CSRF-Token' => $token]),
        new \STDW\Http\Response(),
        static fn ($request, $response) => $response->withBody('ok'),
    );

    expect($response->getBody())->toBe('ok');
});
