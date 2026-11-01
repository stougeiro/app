<?php declare(strict_types=1);

use function Sourcerer\env;

use App\Provider\CacheServiceProvider;
use App\Provider\ViewServiceProvider;
use App\Provider\HttpServiceProvider;
use App\Provider\PipelineServiceProvider;
use App\Provider\AppServiceProvider;

use App\HelloWorld\HelloWorldModuleProvider;


return
[
    'environment' => env('environment'),

    'providers' => [
        CacheServiceProvider::class,
        ViewServiceProvider::class,
        HttpServiceProvider::class,
        PipelineServiceProvider::class,
        AppServiceProvider::class,
    ],

    'modules' => [
        HelloWorldModuleProvider::class,
    ],
];
