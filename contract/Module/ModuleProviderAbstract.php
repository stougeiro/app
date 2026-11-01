<?php declare(strict_types=1);

namespace App\Contract\Module;

use Sourcerer\Contract\Module\ModuleProviderAbstract as SourcererModuleProviderAbstract;

use STDW\Contract\View\AliasManagerInterface;


abstract class ModuleProviderAbstract extends SourcererModuleProviderAbstract
{
    /**
     * Registers a view namespace for this module.
     *
     * @param string $alias
     * @param string $path
     * @return void
     */
    protected function views(string $alias, string $path): void
    {
        /** @var AliasManagerInterface $views */
        $views = $this->container->get(AliasManagerInterface::class);

        $views->alias($alias, $path);
    }
}
