<?php declare(strict_types=1);

namespace App\Error;

use App\Core\Module\ModuleProviderAbstract;


final class ErrorModuleProvider extends ModuleProviderAbstract
{
    public function configure(): void
    {
        $this->views('error', __DIR__ . '/View');
    }
}
