<?php declare(strict_types=1);

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


test('exposes backup and nested webroot settings', function () {
    $target = DeployFixtures::target(backupKeep: 5, nestedWebroots: ['dev', 'staging']);

    expect($target->backupKeep)->toBe(5)
        ->and($target->nestedWebroots)->toBe(['dev', 'staging']);
});
