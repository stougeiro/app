#!/usr/bin/env php
<?php declare(strict_types=1);

use Sourcerer\Tool\Contract\ToolInterface;
use Sourcerer\Tool\Manager\Application;

$toolsRoot = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($toolsRoot): void {
    $prefixes = [
        'Sourcerer\\Tool\\Contract\\' => $toolsRoot .'/contract/src/',
        'Sourcerer\\Tool\\Code\\' => $toolsRoot .'/code/src/',
        'Sourcerer\\Tool\\Schema\\' => $toolsRoot .'/schema/src/',
        'Sourcerer\\Tool\\Manager\\' => $toolsRoot .'/manager/src/',
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

$projectRoot = getenv('SOURCERER_APP_ROOT');

if ($projectRoot === false || $projectRoot === '') {
    $projectRoot = getenv('SOURCERER_ROOT');
}

if ($projectRoot === false || $projectRoot === '') {
    $projectRoot = getcwd() ?: dirname($toolsRoot);
}

try {
    /** @var list<class-string<ToolInterface>> $tools */
    $tools = require __DIR__ .'/tools.php';
    $commands = [];
    $groups = [];

    foreach ($tools as $toolClass) {
        $tool = new $toolClass();
        $commands += $tool->commands($projectRoot);
        $groups[$tool->name()] = [
            'description' => $tool->description(),
            'version' => $tool->version(),
        ];
    }

    $application = new Application($commands, groups: $groups);
} catch (Throwable $exception) {
    fwrite(STDERR, 'error: '. $exception->getMessage() . PHP_EOL);
    exit(1);
}

$argv = $_SERVER['argv'] ?? [];

if ( ! is_array($argv)) {
    $argv = [];
}

$tokens = [];

foreach ($argv as $token) {
    if (is_scalar($token)) {
        $tokens[] = (string) $token;
    }
}

exit($application->run(array_slice($tokens, 1)));
