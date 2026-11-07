<?php declare(strict_types=1);

use Sourcerer\Path;

use App\Core\Log\PhpErrorLog;


date_default_timezone_set('America/Sao_Paulo');

const ROOT = __DIR__ . '/../';


require_once ROOT .'../vendor/autoload.php';

Path::register('root', ROOT);
Path::register('app', 'module/', 'root');
Path::register('config', 'config/', 'root');
Path::register('storage', 'storage/', 'root');
Path::register('cache', 'cache/', 'storage');
Path::register('log', 'log/', 'storage');
Path::register('session', 'session/', 'storage');
Path::register('temp', 'temp/', 'storage');
Path::register('upload', 'upload/', 'storage');

PhpErrorLog::configure(Path::get('log') .'php-error-'. date('Ymd') .'.log');
