<?php declare(strict_types=1);

use Sourcerer\Path;


return
[
    'handler' => 'file',

    'storage' => Path::get('cache'),

    'associative' => false,
];
