<?php declare(strict_types=1);

namespace App\HelloWorld;

use App\Contract\Module\ModuleProviderAbstract;


/**
 * The module's single entry point: it consumes the configured application to
 * load its routes, its middlewares and its view namespace ("hello:").
 */
final class HelloWorldModuleProvider extends ModuleProviderAbstract
{
    public function configure(): void
    {
        $this->middlewares(__DIR__ . '/Config/middlewares.php');
        $this->routes(__DIR__ . '/Config/routes.php');
        $this->views('hello', __DIR__ . '/View');
    }
}
