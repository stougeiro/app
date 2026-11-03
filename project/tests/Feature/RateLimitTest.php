<?php declare(strict_types=1);

use App\Core\Security\RateLimit;
use App\Core\Security\Middleware\RateLimitMiddleware;
use Sourcerer\Contract\Error\HttpException;
use STDW\Http\Response;
use Tests\Fixtures\ArrayCache;
use Tests\Fixtures\FakeRequest;

test('rate limit allows requests below the limit and rejects the next', function () {
    $limiter = new RateLimit(new ArrayCache(), 2, 60);

    expect($limiter->hit('client|GET|/'))->toBeNull()
        ->and($limiter->hit('client|GET|/'))->toBeNull()
        ->and($limiter->hit('client|GET|/'))->toBeInt();
});

test('rate limit middleware rejects with 429 and retry after', function () {
    $middleware = new RateLimitMiddleware(new RateLimit(new ArrayCache(), 1, 60));
    $request = new FakeRequest('GET', 'http://localhost/');

    $middleware->process($request, new Response(), static fn ($request, $response) => $response);

    $error = null;

    try {
        $middleware->process($request, new Response(), static fn ($request, $response) => $response);
    } catch (HttpException $exception) {
        $error = $exception;
    }

    expect($error)->toBeInstanceOf(HttpException::class)
        ->and($error->status())->toBe(429)
        ->and($error->headers())->toHaveKey('Retry-After');
});
