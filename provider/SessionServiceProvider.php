<?php declare(strict_types=1);

namespace App\Provider;

use STDW\Contract\Container\ContainerInterface;
use STDW\Contract\Container\ServiceProviderAbstracted;
use STDW\Contract\Session\FlashInterface;
use STDW\Contract\Session\SessionConfigInterface;
use STDW\Contract\Session\SessionInterface;
use STDW\Session\Flash;
use STDW\Session\Session;
use STDW\Session\SessionConfig;

use function Sourcerer\config;


final class SessionServiceProvider extends ServiceProviderAbstracted
{
    public function register(): void
    {
        $this->container->singleton(SessionConfigInterface::class, static function (): SessionConfigInterface {
            /** @var array{handler?: string, name?: string, storage?: string, cookie?: array{lifetime?: int, same_site?: 'Lax'|'None'|'Strict'}, gc?: array{maxlifetime?: int, probability?: int, divisor?: int}, guard?: array{regeneration?: int}, extra?: array<string, mixed>} $config */
            $config = config('session');

            return new SessionConfig($config);
        });

        $this->container->singleton(SessionInterface::class, static function (ContainerInterface $container): SessionInterface {
            /** @var SessionConfigInterface $config */
            $config = $container->get(SessionConfigInterface::class);

            return new Session($config);
        });

        $this->container->singleton(FlashInterface::class, static function (ContainerInterface $container): FlashInterface {
            /** @var SessionInterface $session */
            $session = $container->get(SessionInterface::class);

            return new Flash($session);
        });
    }
}
