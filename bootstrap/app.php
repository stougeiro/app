<?php declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

const ROOT = __DIR__ . '/../';

require_once ROOT .'vendor/autoload.php';


use Sourcerer\Path;

Path::register('root', ROOT);
Path::register('app', 'app/', 'root');
Path::register('config', 'config/', 'root');
Path::register('storage', 'storage/', 'root');
Path::register('cache', 'cache/', 'storage');
Path::register('log', 'log/', 'storage');
Path::register('session', 'session/', 'storage');
Path::register('temp', 'temp/', 'storage');
