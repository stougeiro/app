<?php declare(strict_types=1);

namespace App\Core\Security;

use STDW\Contract\Cache\CacheInterface;
use STDW\Contract\Container\ContainerInterface;
use STDW\Contract\Container\ServiceFactoryInterface;

use function Sourcerer\config;


final class RateLimit implements ServiceFactoryInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $limit = 60,
        private readonly int $window = 60,
    ) { }


    public static function factory(ContainerInterface $container): static
    {
        $config = config('security.rate_limit', []);
        $limit = is_array($config) && is_int($config['limit'] ?? null) ? $config['limit'] : 60;
        $window = is_array($config) && is_int($config['window'] ?? null) ? $config['window'] : 60;

        /** @var CacheInterface $cache */
        $cache = $container->get(CacheInterface::class);

        return new self($cache, max(1, $limit), max(1, $window));
    }


    /**
     * Returns null when the request is allowed, or the seconds to wait when
     * the limit has been exceeded.
     */
    public function hit(string $key, ?int $limit = null, ?int $window = null): ?int
    {
        $limit ??= $this->limit;
        $window ??= $this->window;

        $cacheKey = 'rate:'. hash('sha256', $key);
        $now = time();
        $entry = $this->cache->get($cacheKey);
        $entry = is_object($entry) ? (array) $entry : $entry;

        if (
               ! is_array($entry)
            || ! isset($entry['count'], $entry['reset'])
            || ! is_int($entry['count'])
            || ! is_int($entry['reset'])
            || $entry['reset'] <= $now
        ) {
            $count = 1;
            $reset = $now + $window;
        } else {
            $count = $entry['count'] + 1;
            $reset = $entry['reset'];
        }

        if ($count > $limit) {
            return max(1, $reset - $now);
        }

        $this->cache->set($cacheKey, [
            'count' => $count,
            'reset' => $reset,
        ], max(1, $reset - $now));

        return null;
    }
}
