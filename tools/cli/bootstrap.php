<?php declare(strict_types=1);

use App\Provider\CacheServiceProvider;
use App\Provider\DatabaseServiceProvider;

$projectRoot = getenv('SOURCERER_APP_ROOT');

if ($projectRoot === false || $projectRoot === '') {
    $projectRoot = dirname(__DIR__, 2);
}

require_once rtrim($projectRoot, '/') .'/application/bootstrap/app.php';

$container = container();

(new CacheServiceProvider($container))->register();
(new DatabaseServiceProvider($container))->register();

return $container;
