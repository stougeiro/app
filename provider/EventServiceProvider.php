<?php declare(strict_types=1);

namespace App\Provider;

use STDW\Contract\Container\ServiceProviderAbstracted;
use STDW\Contract\Event\EventManagerInterface;

use Sourcerer\Path;


final class EventServiceProvider extends ServiceProviderAbstracted
{
    public function register(): void
    {
        $this->container->singleton(EventManagerInterface::class, static function (): EventManagerInterface {
            return events();
        });
    }

    public function boot(): void
    {
        $file = Path::get('config') .'events.php';

        if ( ! is_file($file)) {
            return;
        }

        /** @var EventManagerInterface $events */
        $events = $this->container->get(EventManagerInterface::class);

        $events->load($file);
    }
}
