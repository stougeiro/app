<?php declare(strict_types=1);

namespace App\Provider;

use STDW\Contract\Container\ServiceProviderAbstracted;
use STDW\Contract\Container\ContainerInterface;
use STDW\Contract\Http\Router\RouteCollectionInterface;
use STDW\Contract\Http\Router\RouterInterface;
use STDW\Contract\Cache\CacheInterface;
use STDW\Http\Router\RouteCollection;
use STDW\Http\Router\Router;


/**
 * Routing plumbing: the route collection and the router. The router is lazy,
 * so it only snapshots the route collection at the dispatch, after every
 * module had the chance to load its routes.
 */
final class HttpServiceProvider extends ServiceProviderAbstracted
{
    public function register(): void
    {
        $this->container->singleton(RouteCollectionInterface::class, static function (ContainerInterface $container): RouteCollectionInterface {
            /** @var CacheInterface $cache */
            $cache = $container->get(CacheInterface::class);

            return new RouteCollection($cache);
        });

        $this->container->singleton(RouterInterface::class, static function (ContainerInterface $container): RouterInterface {
            /** @var RouteCollectionInterface $collection */
            $collection = $container->get(RouteCollectionInterface::class);

            /** @var CacheInterface $cache */
            $cache = $container->get(CacheInterface::class);

            /** @var array{cache: bool} $config */
            $config = config('router');

            return new Router($collection, $cache, $config['cache']);
        });
    }
}
