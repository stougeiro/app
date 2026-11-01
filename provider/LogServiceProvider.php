<?php declare(strict_types=1);

namespace App\Provider;

use STDW\Contract\Container\ServiceProviderAbstracted;
use Sourcerer\Contract\Log\LogInterface;
use Sourcerer\Log\Log;
use Sourcerer\Path;


final class LogServiceProvider extends ServiceProviderAbstracted
{
    public function register(): void
    {
        $this->container->singleton(LogInterface::class, static function (): LogInterface {
            return new Log(Path::get('log') .'app.log');
        });
    }
}
