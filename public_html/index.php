<?php declare(strict_types=1);

use Sourcerer\Http\Kernel;
use Sourcerer\Error\Fallback;

use STDW\Http\Request;


try
{
    require_once __DIR__ . '/../project/bootstrap/app.php';

    $kernel = Kernel::create();

    $kernel->handle(new Request())->send();
    $kernel->terminate();
}

catch (Throwable $error)
{
    Fallback::emit($error);
}
