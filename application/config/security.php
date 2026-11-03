<?php declare(strict_types=1);


return
[
    'csrf' => [
        'field' => '_token',
        'header' => 'X-CSRF-Token',
    ],

    'rate_limit' => [
        'limit' => 60,
        'window' => 60,
    ],
];
