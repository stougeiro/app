<?php declare(strict_types=1);

use Sourcerer\Path;


return
[
    'default' => [
        'driver' => 'sqlite',
        'database' => Path::get('storage') .'database.sqlite',
    ],
];
