<?php declare(strict_types=1);

use Sourcerer\Tool\Code\ShellResult;
use Sourcerer\Tool\Code\Ssh;
use Tests\Feature\Code\DeployFixtures;
use Tests\Feature\Code\FakeShell;


test('builds ssh argv without an identity', function () {
    $ssh = new Ssh(new FakeShell(fn () => new ShellResult(0, '', '')));
    $target = DeployFixtures::target();

    expect($ssh->argv($target, 'echo ok'))->toBe([
        'ssh',
        '-p', '22',
        '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=15',
        'deploy@staging.example.com',
        'echo ok',
    ]);
});


test('builds ssh argv with an identity', function () {
    $ssh = new Ssh(new FakeShell(fn () => new ShellResult(0, '', '')));
    $target = DeployFixtures::target(identity: '/home/deploy/.ssh/key');

    expect($ssh->argv($target, 'echo ok'))->toBe([
        'ssh',
        '-p', '22',
        '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=15',
        '-i', '/home/deploy/.ssh/key',
        'deploy@staging.example.com',
        'echo ok',
    ]);
});


test('run forwards argv and stdin through the shell', function () {
    $shell = new FakeShell(fn () => new ShellResult(0, 'ok', ''));
    $ssh = new Ssh($shell);
    $target = DeployFixtures::target();

    $result = $ssh->run($target, 'cat', 'payload');

    expect($result->output())->toBe('ok')
        ->and($shell->calls)->toHaveCount(1)
        ->and($shell->calls[0]['stdin'])->toBe('payload')
        ->and($shell->calls[0]['command'])->toBe($ssh->argv($target, 'cat'));
});


test('must returns a successful result', function () {
    $ssh = new Ssh(new FakeShell(fn () => new ShellResult(0, ' ok ', '')));

    expect($ssh->must(DeployFixtures::target(), 'true')->output())->toBe('ok');
});


test('must throws with the remote detail on failure', function () {
    $ssh = new Ssh(new FakeShell(fn () => new ShellResult(1, '', 'denied')));

    expect(fn () => $ssh->must(DeployFixtures::target(), 'true'))
        ->toThrow(RuntimeException::class, 'denied');
});


test('quotes remote commands', function () {
    expect(Ssh::quote('a b'))->toBe("'a b'");
});
