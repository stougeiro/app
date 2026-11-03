<?php declare(strict_types=1);

use App\Core\Error\ViewErrorRenderer;


return
[
    'renderer' => ViewErrorRenderer::class,

    'mode' => 'html', // html | json

    'messages' => [
        // 404 => [
        //     'title' => 'Page not found',
        //     'message' => 'The requested resource does not exist.',
        // ],
    ],

    'snippet' => [
        'pad' => 10,
    ],

    'log' => [
        'redact' => [ ],
    ],
];
