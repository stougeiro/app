#!/usr/bin/env php
<?php declare(strict_types=1);

use Tool\Cli\Application;
use Tool\Contract\ToolInterface;

$projectRoot = getenv('SOURCERER_APP_ROOT');

if ($projectRoot === false || $projectRoot === '') {
    $projectRoot = dirname(__DIR__, 2);
}

$toolsRoot = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($toolsRoot): void {
    $prefixes = [
        'Tool\\Contract\\' => $toolsRoot .'/contract/src/',
        'Tool\\Deploy\\' => $toolsRoot .'/deploy/src/',
        'Tool\\Cli\\' => $toolsRoot .'/cli/src/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if ( ! str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) .'.php';

        if (is_file($file)) {
            require_once $file;
        }

        return;
    }
});

try {
    /** @var \STDW\Contract\Container\ContainerInterface $container */
    $container = require __DIR__ .'/bootstrap.php';

    /** @var list<class-string<ToolInterface>> $tools */
    $tools = require __DIR__ .'/tools.php';
    $commands = [];

    foreach ($tools as $toolClass) {
        $commands += (new $toolClass())->commands($container);
    }

    $application = new Application($commands);
} catch (Throwable $exception) {
    fwrite(STDERR, 'error: '. $exception->getMessage() . PHP_EOL);
    exit(1);
}

exit($application->run(array_slice($argv, 1)));
