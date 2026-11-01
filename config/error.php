<?php declare(strict_types=1);

use Sourcerer\Path;


return
[
    'mode' => 'html', // html | json

    'development' => [
        'view' => 'error:development',
        'snippet' => 10,
    ],

    'log' => [
        'path' => Path::get('log') .'error.log',
        'trace_from' => 500,
    ],
];
