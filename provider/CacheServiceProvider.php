<?php declare(strict_types=1);

namespace App\Provider;

use STDW\Contract\Container\ServiceProviderAbstracted;
use STDW\Cache\Cache;
use STDW\Cache\CacheConfig;
use STDW\Contract\Cache\CacheInterface;


/**
 * Cache: the vendor cache service, configured from config/cache.php.
 */
final class CacheServiceProvider extends ServiceProviderAbstracted
{
    public function register(): void
    {
        $this->container->singleton(CacheInterface::class, static function (): CacheInterface {
            /** @var array{handler: string, storage: string, associative: bool} $config */
            $config = config('cache');

            return new Cache(new CacheConfig($config));
        });
    }
}
