<?php declare(strict_types=1);

use Tests\Feature\Code\DeployFixtures;
use Tests\Feature\Code\FakeShell;
use Sourcerer\Tool\Code\Lock\TargetLock;


function deployLockShell(array &$state): FakeShell
{
    return new FakeShell(function (array $command, ?string $stdin = null) use (&$state): \Sourcerer\Tool\Code\ShellResult {
        $remote = (string) $command[count($command) - 1];

        if (str_contains($remote, 'date +%s')) {
            return new \Sourcerer\Tool\Code\ShellResult(0, (string) time(), '');
        }

        if (str_contains($remote, 'mkdir ') && str_contains($remote, 'lock')) {
            if ($state['locked']) {
                return new \Sourcerer\Tool\Code\ShellResult(0, '__EXISTS__', '');
            }

            $state['locked'] = true;

            return new \Sourcerer\Tool\Code\ShellResult(0, '__LOCKED__', '');
        }

        if (str_contains($remote, 'cat ') && str_contains($remote, 'owner.json')) {
            return new \Sourcerer\Tool\Code\ShellResult(0, (string) ($state['owner'] ?? ''), '');
        }

        if (str_contains($remote, 'cat > ') && str_contains($remote, 'owner.json')) {
            $state['owner'] = (string) $stdin;

            return new \Sourcerer\Tool\Code\ShellResult(0, '', '');
        }

        if (str_contains($remote, 'rm -rf ') && str_contains($remote, 'lock')) {
            $state['locked'] = false;
            $state['owner'] = null;

            return new \Sourcerer\Tool\Code\ShellResult(0, '', '');
        }

        return new \Sourcerer\Tool\Code\ShellResult(0, '', '');
    });
}


function deployLockOwner(int $age): string
{
    return json_encode([
        'by' => ['name' => 'Maria', 'email' => 'maria@example.com'],
        'host' => 'laptop',
        'pid' => 456,
        'action' => 'push',
        'startedAt' => gmdate('c', time() - $age),
        'startedAtEpoch' => time() - $age,
    ], JSON_THROW_ON_ERROR);
}


test('locks only production', function () {
    $state = ['locked' => false, 'owner' => null];
    $shell = deployLockShell($state);
    $lock = new TargetLock(DeployFixtures::target('staging'), $shell, DeployFixtures::operator(), 'push');

    expect($lock->acquire())->toBeNull()
        ->and($shell->calls)->toBe([]);
});


test('rejects a valid production lock without force', function () {
    $state = ['locked' => true, 'owner' => deployLockOwner(60)];
    $shell = deployLockShell($state);
    $lock = new TargetLock(DeployFixtures::target('production'), $shell, DeployFixtures::operator(), 'push');

    expect(fn () => $lock->acquire())
        ->toThrow(RuntimeException::class, "locked by Maria");
});


test('replaces a stale production lock', function () {
    $state = ['locked' => true, 'owner' => deployLockOwner(4 * 3600)];
    $shell = deployLockShell($state);
    $lock = new TargetLock(DeployFixtures::target('production'), $shell, DeployFixtures::operator(), 'push');

    expect($lock->acquire())->toBeNull()
        ->and($state['locked'])->toBeTrue();

    $lock->release();

    expect($state['locked'])->toBeFalse();
});


test('force replaces a valid lock and returns the previous owner', function () {
    $state = ['locked' => true, 'owner' => deployLockOwner(60)];
    $shell = deployLockShell($state);
    $lock = new TargetLock(DeployFixtures::target('production'), $shell, DeployFixtures::operator(), 'push');

    $previous = $lock->acquire(true);

    expect($previous['by']['name'] ?? null)->toBe('Maria')
        ->and($state['locked'])->toBeTrue();

    $lock->release();

    expect($state['locked'])->toBeFalse();
});
