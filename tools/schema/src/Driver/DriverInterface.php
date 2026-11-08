<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Driver;

use PDO;
use Sourcerer\Tool\Schema\Domain\SchemaState;


interface DriverInterface
{
    public function driver(): string;

    public function version(PDO $pdo): string;

    /** @return list<string> */
    public function unsupportedObjects(PDO $pdo): array;

    public function inspect(PDO $pdo): SchemaState;
}
