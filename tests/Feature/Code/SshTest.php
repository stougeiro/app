<?php declare(strict_types=1);

use Sourcerer\Tool\Code\ShellResult;
use Sourcerer\Tool\Code\Ssh;
use Sourcerer\Tool\Code\SshAuth;
use Tests\Feature\Code\DeployFixtures;
use Tests\Feature\Code\FakeShell;


function deployPasswordFile(string $password = 'secret'): string
{
    $file = tempnam(sys_get_temp_dir(), 'deploy-password-');

    if ($file === false) {
        throw new RuntimeException('could not create password file');
    }

    file_put_contents($file, $password);

    return $file;
}


test('builds ssh argv with a key', function () {
    $ssh = new Ssh(new FakeShell(fn () => new ShellResult(0, '', '')));
    $target = DeployFixtures::target(auth: new SshAuth(SshAuth::TYPE_KEY, '/home/deploy/.ssh/key'));

    expect($ssh->argv($target, 'echo ok'))->toBe([
        'ssh',
        '-p', '22',
        '-o', 'BatchMode=yes',
        '-i', '/home/deploy/.ssh/key',
        '-o', 'ConnectTimeout=15',
        'deploy@staging.example.com',
        'echo ok',
    ]);
});


test('builds ssh argv with a password', function () {
    $file = deployPasswordFile();
    $ssh = new Ssh(new FakeShell(fn () => new ShellResult(0, '', '')));
    $target = DeployFixtures::target(auth: new SshAuth(SshAuth::TYPE_PASS, $file));

    try {
        expect($ssh->argv($target, 'echo ok'))->toBe([
            'sshpass', '-e', 'ssh',
            '-p', '22',
            '-o', 'BatchMode=no',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'PreferredAuthentications=password',
            '-o', 'PubkeyAuthentication=no',
            '-o', 'NumberOfPasswordPrompts=1',
            '-o', 'ConnectTimeout=15',
            'deploy@staging.example.com',
            'echo ok',
        ]);
    } finally {
        unlink($file);
    }
});


test('run forwards argv, stdin and auth env through the shell', function () {
    $file = deployPasswordFile('secret');
    $shell = new FakeShell(fn () => new ShellResult(0, 'ok', ''));
    $ssh = new Ssh($shell);
    $target = DeployFixtures::target(auth: new SshAuth(SshAuth::TYPE_PASS, $file));

    try {
        $result = $ssh->run($target, 'cat', 'payload');

        expect($result->output())->toBe('ok')
            ->and($shell->calls)->toHaveCount(1)
            ->and($shell->calls[0]['stdin'])->toBe('payload')
            ->and($shell->calls[0]['command'])->toBe($ssh->argv($target, 'cat'))
            ->and($shell->calls[0]['env'])->toBe(['SSHPASS' => 'secret']);
    } finally {
        unlink($file);
    }
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
