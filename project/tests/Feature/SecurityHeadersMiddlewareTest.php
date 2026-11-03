<?php declare(strict_types=1);

use App\Core\Security\Middleware\SecurityHeadersMiddleware;
use STDW\Contract\Http\ResponseInterface;
use STDW\Http\Response;
use Tests\Fixtures\FakeRequest;

test('security headers are applied before and after the next middleware', function () {
    $middleware = new SecurityHeadersMiddleware();
    $seenBeforeNext = false;

    $response = $middleware->process(
        new FakeRequest('GET', 'http://localhost/'),
        new Response(),
        static function ($request, ResponseInterface $response) use (&$seenBeforeNext): ResponseInterface {
            $seenBeforeNext = $response->hasHeader('X-Frame-Options');

            return $response->withHeader('X-Test', 'preserved');
        },
    );

    expect($seenBeforeNext)->toBeTrue()
        ->and($response->getHeader('X-Frame-Options'))->toBe('DENY')
        ->and($response->getHeader('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->getHeader('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->getHeader('Permissions-Policy'))->toBe('geolocation=(), microphone=(), camera=()')
        ->and($response->getHeader('Content-Security-Policy'))->toContain("style-src 'self'")
        ->and($response->getHeader('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($response->getHeader('Content-Security-Policy'))->toContain("form-action 'self'")
        ->and($response->getHeader('Content-Security-Policy'))->not->toContain('unsafe-inline')
        ->and($response->getHeader('X-Test'))->toBe('preserved');
});

test('security headers are reapplied to a replacement response', function () {
    $middleware = new SecurityHeadersMiddleware();

    $response = $middleware->process(
        new FakeRequest('GET', 'http://localhost/'),
        new Response(),
        static fn ($request, $response) => (new Response())->withBody('replaced'),
    );

    expect($response->getBody())->toBe('replaced')
        ->and($response->getHeader('X-Frame-Options'))->toBe('DENY')
        ->and($response->getHeader('Content-Security-Policy'))->toContain("style-src 'self'");
});
