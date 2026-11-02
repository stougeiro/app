<?php declare(strict_types=1);

use App\Core\Http\SecurityHeadersMiddleware;


/**
 * Application level middlewares: they run before the dispatcher, for every
 * request (including the ones that match no route).
 */
return
[
    // SecurityHeadersMiddleware::class,
];
