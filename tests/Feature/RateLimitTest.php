<?php declare(strict_types=1);

use App\Core\Security\RateLimit;
use App\Core\Security\Middleware\RateLimitMiddleware;
use Sourcerer\Contract\Error\HttpException;
use Sourcerer\Http\Kernel;
use STDW\Http\Response;
use Tests\Fixtures\ArrayCache;
use Tests\Fixtures\FakeRequest;

test('rate limit allows requests below the limit and rejects the next', function () {
    $limiter = new RateLimit(new ArrayCache(), 2, 60);

    expect($limiter->hit('client|GET|/'))->toBeNull()
        ->and($limiter->hit('client|GET|/'))->toBeNull()
        ->and($limiter->hit('client|GET|/'))->toBeInt();
});

test('rate limit factory builds a limiter from the container', function () {
    Kernel::create()->handle(new FakeRequest('GET', 'http://localhost/'));

    $limiter = RateLimit::factory(container());
    $client = 'factory-client-'. bin2hex(random_bytes(8));

    for ($i = 0; $i < 60; $i++) {
        expect($limiter->hit($client))->toBeNull();
    }

    expect($limiter->hit($client))->toBeInt();
});

test('rate limit reads object entries from non-associative caches', function () {
    $limiter = new RateLimit(new ArrayCache(asObject: true), 2, 60);

    expect($limiter->hit('client'))->toBeNull()
        ->and($limiter->hit('client'))->toBeNull()
        ->and($limiter->hit('client'))->toBeInt();
});

test('rate limit resets an expired window', function () {
    $cache = new ArrayCache();
    $key = 'rate:'. hash('sha256', 'client');
    $cache->set($key, ['count' => 5, 'reset' => time() - 1]);

    $limiter = new RateLimit($cache, 2, 60);

    expect($limiter->hit('client'))->toBeNull()
        ->and($cache->get($key))->toMatchArray(['count' => 1]);
});

test('rate limit resets a malformed cache entry', function () {
    $cache = new ArrayCache();
    $key = 'rate:'. hash('sha256', 'client');
    $cache->set($key, ['count' => 'invalid', 'reset' => time() + 60]);

    $limiter = new RateLimit($cache, 2, 60);

    expect($limiter->hit('client'))->toBeNull()
        ->and($cache->get($key))->toMatchArray(['count' => 1]);
});

test('rate limit accepts per-call limits', function () {
    $limiter = new RateLimit(new ArrayCache(), 60, 60);

    expect($limiter->hit('client', 1, 10))->toBeNull()
        ->and($limiter->hit('client', 1, 10))->toBeInt();
});

test('rate limit middleware tolerates a non-string remote address', function () {
    $previous = $_SERVER['REMOTE_ADDR'] ?? null;
    $_SERVER['REMOTE_ADDR'] = ['invalid'];

    try {
        $middleware = new RateLimitMiddleware(new RateLimit(new ArrayCache(), 1, 60));

        $response = $middleware->process(
            new FakeRequest('GET', 'http://localhost/'),
            new Response(),
            static fn ($request, $response) => $response,
        );

        expect($response->getStatus())->toBe(200);
    } finally {
        if ($previous === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $previous;
        }
    }
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
