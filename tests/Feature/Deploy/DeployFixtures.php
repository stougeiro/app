<?php declare(strict_types=1);

namespace Tests\Feature\Deploy;

use Deploy\Target;


final class DeployFixtures
{
    /**
     * @param list<string> $nestedWebroots
     */
    public static function target(
        string $name = 'staging',
        ?string $identity = null,
        string $root = '/srv/app',
        string $webroot = '/srv/app/public_html',
        int $backupKeep = 3,
        array $nestedWebroots = [],
    ): Target {
        return new Target(
            $name,
            'staging.example.com',
            'deploy',
            22,
            $identity,
            $webroot,
            $root,
            'https://staging.example.com',
            ['/.well-known/***'],
            $backupKeep,
            $nestedWebroots,
        );
    }
}
