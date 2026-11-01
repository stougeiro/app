<?php declare(strict_types=1);

namespace App\Provider;

use STDW\Contract\Container\ContainerInterface;
use STDW\Contract\Container\ServiceProviderAbstracted;
use STDW\Contract\Http\MiddlewareInterface;
use STDW\Contract\Http\MiddlewareManagerInterface;
use STDW\Contract\Http\RequestHandlerInterface;
use STDW\Http\Handler\RequestHandler;
use STDW\Http\Middleware\MiddlewareManager;

use Sourcerer\Contract\Http\RoutingMiddlewareCollectionInterface;
use Sourcerer\Http\RoutingDispatcherMiddleware;
use Sourcerer\Http\RoutingMiddlewareCollection;

use function Sourcerer\config;


/**
 * Request pipeline: the middleware manager, the middleware registry (global,
 * module and controller scopes), the dispatcher and the request handler. The
 * chain is assembled here: the globals at the front and the dispatcher as the
 * final handler.
 */
final class PipelineServiceProvider extends ServiceProviderAbstracted
{
    public function register(): void
    {
        $this->container->singleton(MiddlewareManagerInterface::class, MiddlewareManager::class);

        $this->container->singleton(RoutingMiddlewareCollectionInterface::class, static function (ContainerInterface $container): RoutingMiddlewareCollectionInterface {
            return new RoutingMiddlewareCollection($container);
        });

        $this->container->singleton(RoutingDispatcherMiddleware::class, static function (ContainerInterface $container): RoutingDispatcherMiddleware {
            /** @var RoutingMiddlewareCollectionInterface $middlewares */
            $middlewares = $container->get(RoutingMiddlewareCollectionInterface::class);

            return new RoutingDispatcherMiddleware($container, $middlewares);
        });

        $this->container->singleton(RequestHandlerInterface::class, static function (ContainerInterface $container): RequestHandlerInterface {
            /** @var MiddlewareManagerInterface $manager */
            $manager = $container->get(MiddlewareManagerInterface::class);

            return new RequestHandler($manager);
        });
    }

    public function boot(): void
    {
        /** @var MiddlewareManagerInterface $manager */
        $manager = $this->container->get(MiddlewareManagerInterface::class);

        /** @var array<int, class-string<MiddlewareInterface>> $middlewares */
        $middlewares = config('middlewares', []);
        /** @var array<int, class-string<MiddlewareInterface>> $globals */
        $globals = array_unique($middlewares);

        foreach ($globals as $class) {
            /** @var MiddlewareInterface $middleware */
            $middleware = $this->container->make($class);

            $manager->add($middleware);
        }

        /** @var RoutingDispatcherMiddleware $dispatcher */
        $dispatcher = $this->container->get(RoutingDispatcherMiddleware::class);

        $manager->setFinalHandler($dispatcher);
    }
}
