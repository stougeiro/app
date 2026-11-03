<?php declare(strict_types=1);

use Deploy\Target;
use Tests\Feature\Deploy\DeployFixtures;


test('builds the ssh target', function () {
    expect(DeployFixtures::target()->sshTarget())->toBe('deploy@staging.example.com');
});


test('returns null when no identity is configured', function () {
    expect(DeployFixtures::target()->identityPath())->toBeNull()
        ->and(DeployFixtures::target(identity: '')->identityPath())->toBeNull();
});


test('keeps an absolute identity unchanged', function () {
    expect(DeployFixtures::target(identity: '/home/deploy/.ssh/key')->identityPath())
        ->toBe('/home/deploy/.ssh/key');
});


test('expands a home-relative identity', function () {
    $previous = getenv('HOME');
    putenv('HOME=/home/tester');

    try {
        expect(DeployFixtures::target(identity: '~/.ssh/key')->identityPath())
            ->toBe('/home/tester/.ssh/key');
    } finally {
        if ($previous === false)
        {
            putenv('HOME');
        }
        else
        {
            putenv('HOME='. $previous);
        }
    }
});


test('throws when a home-relative identity has no HOME', function () {
    $previous = getenv('HOME');
    putenv('HOME');

    try {
        expect(fn () => DeployFixtures::target(identity: '~/.ssh/key')->identityPath())
            ->toThrow(RuntimeException::class, 'HOME is not set');
    } finally {
        if ($previous !== false)
        {
            putenv('HOME='. $previous);
        }
    }
});


test('fingerprint data contains the deploy identity fields', function () {
    $target = new Target(
        'staging',
        'staging.example.com',
        'deploy',
        22,
        null,
        '/srv/app/public_html',
        '/srv/app',
        'https://staging.example.com',
        ['/uploads/***'],
        ['dir' => '755', 'file' => '644', 'storage' => '775'],
        true,
        3,
        false,
    );

    expect($target->fingerprintData())->toBe([
        'name' => 'staging',
        'sshHost' => 'staging.example.com',
        'sshUser' => 'deploy',
        'sshPort' => 22,
        'webroot' => '/srv/app/public_html',
        'root' => '/srv/app',
        'url' => 'https://staging.example.com',
        'preserve' => ['/uploads/***'],
        'permissions' => ['dir' => '755', 'file' => '644', 'storage' => '775'],
        'backupEnabled' => true,
        'backupKeep' => 3,
        'requiresPlan' => false,
    ]);
});
