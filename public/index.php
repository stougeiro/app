<?php declare(strict_types=1);

use Sourcerer\Http\Kernel;

use STDW\Http\Request;


try
{
    require_once __DIR__ . '/../bootstrap/app.php';

    $kernel = Kernel::create();

    $kernel->handle(new Request())->send();
    $kernel->terminate();
}

catch (Throwable $error)
{
    error_log((string) $error);

    if (headers_sent()) {
        return;
    }

    header_remove();
    http_response_code(500);
}
