<?php declare(strict_types=1);

use App\HelloWorld\Controller\HelloWorldController;

use App\HelloWorld\Middleware\HelloWorldMiddleware;
use App\HelloWorld\Middleware\HelloWorldOnlyMiddleware;


return [
    /* The whole module. */
    '*' => [
        HelloWorldMiddleware::class,
    ],

    /* A single controller. */
    HelloWorldController::class => [
        HelloWorldOnlyMiddleware::class,
    ],
];
