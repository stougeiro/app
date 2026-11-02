<?php declare(strict_types=1);

namespace App\Core\Http;

use STDW\Contract\Http\MiddlewareInterface;
use STDW\Contract\Http\RequestInterface;
use STDW\Contract\Http\ResponseInterface;

use Closure;


final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'self';";


    public function process(RequestInterface $request, ResponseInterface $response, Closure $next): ResponseInterface
    {
        $response = $this->apply($response);
        $response = $next($request, $response);

        return $this->apply($response);
    }


    private function apply(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
            ->withHeader('Content-Security-Policy', self::CSP);
    }
}
