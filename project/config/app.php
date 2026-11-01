<?php declare(strict_types=1);

use function Sourcerer\env;

use App\Provider\CacheServiceProvider;
use App\Provider\DatabaseServiceProvider;
use App\Provider\EventServiceProvider;
use App\Provider\LogServiceProvider;
use App\Provider\SessionServiceProvider;
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
        SessionServiceProvider::class,
        EventServiceProvider::class,
        DatabaseServiceProvider::class,
        LogServiceProvider::class,
        HttpServiceProvider::class,
        PipelineServiceProvider::class,
        AppServiceProvider::class,
    ],

    'modules' => [
        HelloWorldModuleProvider::class,
    ],
];
