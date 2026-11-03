<?php declare(strict_types=1);

use Sourcerer\Http\Kernel;
use Sourcerer\Error\Fallback;

use STDW\Http\Request;


if (is_file(__DIR__ .'/.maintenance')) {
    http_response_code(503);

    header('Retry-After: 3600');
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');

    $page = __DIR__ .'/maintenance.html';

    if (is_file($page) && is_readable($page)) {
        readfile($page);
    }

    exit;
}


try
{
    require_once __DIR__ . '/../application/bootstrap/app.php';

    $kernel = Kernel::create();

    $kernel->handle(new Request())->send();
    $kernel->terminate();
}

catch (Throwable $error)
{
    Fallback::emit($error);
}
