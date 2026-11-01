<?php declare(strict_types=1);

use Sourcerer\Path;


return
[
    'handler' => 'file',

    'name' => 'APP_SESSID',

    'storage' => Path::get('session'),

    'cookie' => [
        'lifetime' => 0,
        'same_site' => 'Lax',
    ],

    'gc' => [
        'maxlifetime' => 1800,
        'probability' => 1,
        'divisor' => 100,
    ],

    'guard' => [
        'regeneration' => 300,
    ],
];
