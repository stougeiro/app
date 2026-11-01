<?php declare(strict_types=1);

namespace App\Provider;

use STDW\Contract\Container\ServiceProviderAbstracted;
use STDW\Contract\Container\ContainerInterface;
use STDW\Contract\View\AliasManagerInterface;
use STDW\Contract\View\ViewManagerInterface;
use STDW\View\Engine\PhpEngine;
use STDW\View\ViewConfig;
use STDW\View\ViewManager;


/**
 * View: the view manager and the alias capability it exposes, configured from
 * config/view.php.
 */
final class ViewServiceProvider extends ServiceProviderAbstracted
{
    public function register(): void
    {
        $this->container->singleton(ViewManagerInterface::class, static function (): ViewManagerInterface {
            /** @var array{storage: string, extension: string, aliases: array<string, string>} $config */
            $config = config('view');

            return new ViewManager(new ViewConfig($config), new PhpEngine());
        });

        $this->container->singleton(AliasManagerInterface::class, static function (ContainerInterface $container): AliasManagerInterface {
            /** @var AliasManagerInterface $view */
            $view = $container->get(ViewManagerInterface::class);

            return $view;
        });
    }
}
