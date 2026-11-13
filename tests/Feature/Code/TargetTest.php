<?php declare(strict_types=1);

use Sourcerer\Tool\Code\SshAuth;
use Tests\Feature\Code\DeployFixtures;


test('builds the ssh target', function () {
    expect(DeployFixtures::target()->sshTarget())->toBe('deploy@staging.example.com');
});


test('exposes the configured auth', function () {
    $auth = new SshAuth(SshAuth::TYPE_PASS, 'auth/locaweb.pass');

    expect(DeployFixtures::target(auth: $auth)->sshAuth)->toBe($auth);
});


test('exposes root base and dir', function () {
    $target = DeployFixtures::target(
        root: '/srv/domain/development',
        rootBase: '/srv/domain',
        rootDir: 'development',
    );

    expect($target->root)->toBe('/srv/domain/development')
        ->and($target->rootBase)->toBe('/srv/domain')
        ->and($target->rootDir)->toBe('development');
});


test('exposes backup and nested webroot settings', function () {
    $target = DeployFixtures::target(backupKeep: 5, nestedWebroots: ['dev', 'staging']);

    expect($target->backupKeep)->toBe(5)
        ->and($target->nestedWebroots)->toBe(['dev', 'staging']);
});
