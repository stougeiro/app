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
    } else {
        echo
        '<!DOCTYPE html>
        <html lang="pt-BR">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Maintenance</title>
                <style rel="stylesheet">
                    html, body { height: 100%; }
                    body { display: flex; align-items: center; justify-content: center; margin: 0; background: #efefef; color: #222; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
                    main { display: flex; flex-direction: column; align-items: center; padding: 24px; text-align: center; }
                    .message { margin-top: 8px; font-size: clamp(18px, 3vw, 28px); font-weight: 400; text-transform: uppercase; line-height: 1.1; letter-spacing: 3px; }
                </style>
            </head>
            <body>
                <h1 class="message">Maintenance</h1>
            </body>
        </html>';
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
