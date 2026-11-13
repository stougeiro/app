<?php declare(strict_types=1);

use Sourcerer\Tool\Code\SshAuth;


function sshAuthFile(string $contents): string
{
    $file = tempnam(sys_get_temp_dir(), 'ssh-auth-');

    if ($file === false) {
        throw new RuntimeException('could not create auth file');
    }

    file_put_contents($file, $contents);

    return $file;
}


test('reads the password exactly as stored', function () {
    $file = sshAuthFile("secret\n");

    try {
        $auth = new SshAuth(SshAuth::TYPE_PASS, $file);

        expect($auth->password())->toBe("secret\n")
            ->and($auth->env())->toBe(['SSHPASS' => "secret\n"]);
    } finally {
        unlink($file);
    }
});


test('rejects a missing password file', function () {
    $auth = new SshAuth(SshAuth::TYPE_PASS, sys_get_temp_dir() .'/missing-ssh-password');

    expect(fn () => $auth->password())
        ->toThrow(RuntimeException::class, 'not readable');
});


test('rejects an empty password file', function () {
    $file = sshAuthFile('');

    try {
        expect(fn () => (new SshAuth(SshAuth::TYPE_PASS, $file))->password())
            ->toThrow(RuntimeException::class, 'empty');
    } finally {
        unlink($file);
    }
});


test('expands a home-relative auth file', function () {
    $previous = getenv('HOME');
    putenv('HOME=/home/tester');

    try {
        expect((new SshAuth(SshAuth::TYPE_KEY, '~/.ssh/key'))->filePath())
            ->toBe('/home/tester/.ssh/key');
    } finally {
        if ($previous === false) {
            putenv('HOME');
        } else {
            putenv('HOME='. $previous);
        }
    }
});


test('throws when a home-relative auth file has no HOME', function () {
    $previous = getenv('HOME');
    putenv('HOME');

    try {
        expect(fn () => (new SshAuth(SshAuth::TYPE_KEY, '~/.ssh/key'))->filePath())
            ->toThrow(RuntimeException::class, 'HOME is not set');
    } finally {
        if ($previous !== false) {
            putenv('HOME='. $previous);
        }
    }
});
