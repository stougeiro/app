<?php declare(strict_types=1);

namespace App\Core\Security\Middleware;

use App\Core\Security\Csrf;

use STDW\Contract\Container\ContainerInterface;
use STDW\Contract\Container\ServiceFactoryInterface;
use STDW\Contract\Http\MiddlewareInterface;
use STDW\Contract\Http\RequestInterface;
use STDW\Contract\Http\ResponseInterface;

use Sourcerer\Contract\Error\HttpException;

use Closure;


final class CsrfMiddleware implements MiddlewareInterface, ServiceFactoryInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];


    public function __construct(
        private readonly Csrf $csrf,
    ) { }


    public static function factory(ContainerInterface $container): static
    {
        /** @var Csrf $csrf */
        $csrf = $container->make(Csrf::class);

        return new self($csrf);
    }


    public function process(RequestInterface $request, ResponseInterface $response, Closure $next): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $next($request, $response);
        }

        if (! $this->csrf->validate($request)) {
            throw new HttpException(403, 'Invalid CSRF token');
        }

        return $next($request, $response);
    }
}
