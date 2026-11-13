<?php declare(strict_types=1);

use Sourcerer\Tool\Code\Remote\Scp;
use Sourcerer\Tool\Code\ShellResult;
use Sourcerer\Tool\Code\Ssh;
use Sourcerer\Tool\Code\SshAuth;
use Sourcerer\Tool\Code\Transport\Transfer;
use Sourcerer\Tool\Support\FileSystem;
use Tests\Feature\Code\DeployFixtures;
use Tests\Feature\Code\FakeShell;


test('rsync applies password auth and env', function () {
    $root = sys_get_temp_dir() .'/transfer-'. bin2hex(random_bytes(6));
    $password = tempnam(sys_get_temp_dir(), 'transfer-password-');

    if ($password === false) {
        throw new RuntimeException('could not create password file');
    }

    file_put_contents($password, 'secret');
    mkdir($root .'/.sourcerer/code/override/staging/public', 0700, true);
    file_put_contents($root .'/.sourcerer/code/override/staging/public/index.php', 'test');

    $shell = new FakeShell(fn (): ShellResult => new ShellResult(0, '', ''));
    $target = DeployFixtures::target(auth: new SshAuth(SshAuth::TYPE_PASS, $password));
    $transfer = new Transfer(
        $root,
        $target,
        $shell,
        new Ssh($shell),
        new Scp($target, $shell),
        DeployFixtures::output(),
    );

    try {
        $transfer->applyOverrides(true);

        $call = $shell->calls[0];
        $command = implode(' ', $call['command']);

        expect($call['command'])->toContain('rsync')
            ->and($call['env'])->toBe(['SSHPASS' => 'secret'])
            ->and($command)->toContain('sshpass')
            ->and($command)->toContain('StrictHostKeyChecking=accept-new');
    } finally {
        FileSystem::removeDir($root);
        unlink($password);
    }
});
