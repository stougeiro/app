<?php declare(strict_types=1);

namespace App\HelloWorld\Middleware;

use STDW\Contract\Http\MiddlewareInterface;
use STDW\Contract\Http\RequestInterface;
use STDW\Contract\Http\ResponseInterface;

use Closure;


/**
 * Module level middleware: it runs for every route of the HelloWorld module
 * and only accumulates on the response, letting the chain continue.
 */
final class HelloWorldMiddleware implements MiddlewareInterface
{
    /**
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param Closure $next
     * @return ResponseInterface
     */
    public function process(RequestInterface $request, ResponseInterface $response, Closure $next): ResponseInterface
    {
        $chain = $response->getHeader('X-Chain');

        $response->withHeader('X-Chain', is_null($chain) ? 'module' : $chain . ' module');

        return $next($request, $response);
    }
}
