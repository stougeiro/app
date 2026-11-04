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

        if ($argument === '--list')
        {
            $options['list'] = true;
            continue;
        }

        if (str_starts_with($argument, '--timestamp='))
        {
            $options['timestamp'] = substr($argument, 12);
            continue;
        }

        if ($argument === '--timestamp')
        {
            $options['timestamp'] = $args[++$index] ?? '';
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
  deploy check    --target=<development|staging|production>
  deploy push     --target=<...>
  deploy rollback --target=<...> --list
  deploy rollback --target=<...> [--timestamp=<Ymd-His>]

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

    if ( ! in_array($command, ['init', 'check', 'push', 'rollback'], true))
    {
        throw new RuntimeException("unknown command: {$command}");
    }

    if ($command === 'init')
    {
        foreach (['target', 'list', 'timestamp'] as $option)
        {
            if (array_key_exists($option, $options))
            {
                throw new RuntimeException("init does not accept --{$option}");
            }
        }

        (new Initializer($root))->run();
        exit(0);
    }

    $targetName = $options['target'] ?? null;

    if ( ! is_string($targetName) || $targetName === '')
    {
        throw new RuntimeException('--target is required');
    }

    if ($command !== 'rollback' && array_key_exists('list', $options))
    {
        throw new RuntimeException('--list is only valid for rollback');
    }

    if ($command !== 'rollback' && array_key_exists('timestamp', $options))
    {
        throw new RuntimeException('--timestamp is only valid for rollback');
    }

    $config = Config::load($root .'/deploy.json');
    $target = $config->target($targetName);
    $deployer = new Deployer($root, $target, new Shell());

    switch ($command)
    {
        case 'check':
            $deployer->check();
            break;

        case 'push':
            $deployer->push();
            break;

        case 'rollback':
            if (array_key_exists('list', $options))
            {
                if (array_key_exists('timestamp', $options))
                {
                    throw new RuntimeException('--list and --timestamp cannot be used together');
                }

                $backups = $deployer->backups();

                if ($backups === [])
                {
                    fwrite(STDOUT, '['. $target->name .'] no backups'. PHP_EOL);
                    break;
                }

                fwrite(STDOUT, '['. $target->name .'] backups:'. PHP_EOL);

                foreach ($backups as $backup)
                {
                    fwrite(STDOUT, $backup . PHP_EOL);
                }

                break;
            }

            $timestamp = $options['timestamp'] ?? null;

            if ($timestamp !== null && (! is_string($timestamp) || $timestamp === ''))
            {
                throw new RuntimeException('--timestamp requires a value');
            }

            $deployer->rollback(is_string($timestamp) ? $timestamp : null);
            break;
    }

    exit(0);
}
catch (Throwable $error)
{
    fwrite(STDERR, 'error: '. $error->getMessage() . PHP_EOL);
    exit(1);
}
