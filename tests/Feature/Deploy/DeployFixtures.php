<?php declare(strict_types=1);

namespace Tests\Feature\Deploy;

use Deploy\Target;


final class DeployFixtures
{
    public static function target(
        string $name = 'staging',
        ?string $identity = null,
        string $root = '/srv/app',
        string $webroot = '/srv/app/public_html',
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
            ['/uploads/***'],
            ['dir' => '755', 'file' => '644', 'storage' => '775'],
            true,
            3,
            false,
        );
    }
}
