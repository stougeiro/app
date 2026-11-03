<?php declare(strict_types=1);

namespace App\Core\Security\Middleware;

use App\Core\Security\RateLimit;

use STDW\Contract\Container\ContainerInterface;
use STDW\Contract\Container\ServiceFactoryInterface;
use STDW\Contract\Http\MiddlewareInterface;
use STDW\Contract\Http\RequestInterface;
use STDW\Contract\Http\ResponseInterface;

use Sourcerer\Contract\Error\HttpException;

use Closure;


final class RateLimitMiddleware implements MiddlewareInterface, ServiceFactoryInterface
{
    public function __construct(
        private readonly RateLimit $limiter,
    ) { }


    public static function factory(ContainerInterface $container): static
    {
        /** @var RateLimit $limiter */
        $limiter = $container->make(RateLimit::class);

        return new self($limiter);
    }


    public function process(RequestInterface $request, ResponseInterface $response, Closure $next): ResponseInterface
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ip = is_string($remote) ? $remote : 'unknown';
        $key = $ip .'|'. $request->getMethod() .'|'. $request->getUri()->getPath();

        $wait = $this->limiter->hit($key);

        if ($wait !== null) {
            throw new HttpException(429, 'Too Many Requests', [
                'Retry-After' => (string) $wait,
            ]);
        }

        return $next($request, $response);
    }
}
