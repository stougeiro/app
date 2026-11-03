#!/usr/bin/env php
<?php declare(strict_types=1);

namespace Deploy;

use RuntimeException;
use Throwable;


$root = dirname(__DIR__, 2);

foreach ([
    'ShellResult',
    'Shell',
    'Target',
    'Config',
    'EnvFile',
    'PlanGate',
    'Ssh',
    'Maintenance',
    'Deployer',
    'Initializer',
] as $class) {
    require_once __DIR__ .'/src/'. $class .'.php';
}


/**
 * @param list<string> $args
 * @return array{command:string,options:array<string, string|bool>}
 */
function parseArguments(array $args): array
{
    $command = $args[0] ?? 'help';
    $options = [];

    for ($index = 1; $index < count($args); $index++)
    {
        $argument = $args[$index];

        if (str_starts_with($argument, '--target='))
        {
            $options['target'] = substr($argument, 9);
            continue;
        }

        if ($argument === '--target')
        {
            $options['target'] = $args[++$index] ?? '';
            continue;
        }

        if ($argument === '--with-env')
        {
            $options['with-env'] = true;
            continue;
        }

        if (str_starts_with($argument, '--backup='))
        {
            $options['backup'] = substr($argument, 9);
            continue;
        }

        if ($argument === '--backup')
        {
            $options['backup'] = $args[++$index] ?? '';
            continue;
        }

        throw new RuntimeException("unknown option: {$argument}");
    }

    return ['command' => $command, 'options' => $options];
}

function usage(): void
{
    fwrite(STDOUT, <<<'TXT'
Usage:
  deploy init
  deploy check       --target=<development|staging|production>
  deploy plan        --target=<...> [--with-env]
  deploy push        --target=<...> [--with-env]
  deploy maintenance --target=<...>
  deploy rollback    --target=<...> [--backup=<ts>]

TXT);
}

try
{
    /** @var list<string> $arguments */
    $arguments = array_slice($argv, 1);
    $parsed = parseArguments($arguments);
    $command = $parsed['command'];
    $options = $parsed['options'];

    if ($command === 'help' || $command === '--help' || $command === '-h')
    {
        usage();
        exit(0);
    }

    if ( ! in_array($command, ['init', 'check', 'plan', 'push', 'maintenance', 'rollback'], true))
    {
        throw new RuntimeException("unknown command: {$command}");
    }

    if ($command === 'init')
    {
        if (array_key_exists('target', $options))
        {
            throw new RuntimeException('init does not accept --target');
        }

        if (array_key_exists('with-env', $options))
        {
            throw new RuntimeException('init does not accept --with-env');
        }

        if (array_key_exists('backup', $options))
        {
            throw new RuntimeException('init does not accept --backup');
        }

        (new Initializer($root))->run();
        exit(0);
    }

    $targetName = $options['target'] ?? null;

    if ( ! is_string($targetName) || $targetName === '')
    {
        throw new RuntimeException('--target is required');
    }

    $config = Config::load($root .'/deploy.json');

    if ( ! in_array($command, ['plan', 'push'], true) && array_key_exists('with-env', $options))
    {
        throw new RuntimeException('--with-env is only valid for plan and push');
    }

    if ($command !== 'rollback' && array_key_exists('backup', $options))
    {
        throw new RuntimeException('--backup is only valid for rollback');
    }

    $target = $config->target($targetName);
    $deployer = new Deployer($root, $target, new Shell());

    switch ($command)
    {
        case 'check':
            $deployer->check();
            break;

        case 'plan':
            $deployer->plan(array_key_exists('with-env', $options));
            break;

        case 'push':
            $deployer->push(array_key_exists('with-env', $options));
            break;

        case 'maintenance':
            $deployer->maintenanceStatus();
            break;

        case 'rollback':
            $backup = $options['backup'] ?? null;

            if ($backup !== null && (! is_string($backup) || $backup === ''))
            {
                throw new RuntimeException('--backup requires a value');
            }

            $deployer->rollback(is_string($backup) ? $backup : null);
            break;
    }

    exit(0);
}
catch (Throwable $error)
{
    fwrite(STDERR, 'error: '. $error->getMessage() . PHP_EOL);
    exit(1);
}
