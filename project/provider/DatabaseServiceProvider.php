<?php declare(strict_types=1);

namespace App\Provider;

use STDW\Contract\Container\ServiceProviderAbstracted;
use STDW\Contract\Pdo\ConnectionInterface;
use STDW\Contract\Pdo\DriverInterface;
use STDW\Pdo\ConnectionRegistry;

use function Sourcerer\config;


final class DatabaseServiceProvider extends ServiceProviderAbstracted
{
    public function register(): void
    {
        /** @var array<string, array<string, mixed>> $definitions */
        $definitions = config('database', []);

        ConnectionRegistry::configure($definitions);

        $this->container->singleton(ConnectionInterface::class, static function (): ConnectionInterface {
            return ConnectionRegistry::get('default');
        });

        $this->container->singleton(DriverInterface::class, static function (): DriverInterface {
            return ConnectionRegistry::resolve('default');
        });
    }
}
