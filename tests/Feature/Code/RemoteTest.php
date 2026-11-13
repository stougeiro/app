<?php declare(strict_types=1);

use Sourcerer\Tool\Code\Release\ReleaseManifest;
use Sourcerer\Tool\Code\Remote\RemoteFiles;
use Sourcerer\Tool\Code\Remote\Scp;
use Sourcerer\Tool\Code\ShellResult;
use Sourcerer\Tool\Code\Ssh;
use Sourcerer\Tool\Code\SshAuth;
use Tests\Feature\Code\DeployFixtures;
use Tests\Feature\Code\FakeShell;


test('scp upload and download build the expected argv', function () {
    $shell = new FakeShell(fn (): ShellResult => new ShellResult(0, '', ''));
    $scp = new Scp(DeployFixtures::target(), $shell);

    $scp->upload('/tmp/a.tar.gz', '/srv/app/.deploy/a.tar.gz');
    $scp->download('/srv/app/.deploy/b.json', '/tmp/b.json');

    expect($shell->calls[0]['command'])->toBe([
        'scp',
        '-P', '22',
        '-o', 'BatchMode=yes',
        '-i', DeployFixtures::target()->sshAuth->file,
        '-o', 'ConnectTimeout=15',
        '/tmp/a.tar.gz',
        'deploy@staging.example.com:/srv/app/.deploy/a.tar.gz',
    ])->and($shell->calls[1]['command'])->toBe([
        'scp',
        '-P', '22',
        '-o', 'BatchMode=yes',
        '-i', DeployFixtures::target()->sshAuth->file,
        '-o', 'ConnectTimeout=15',
        'deploy@staging.example.com:/srv/app/.deploy/b.json',
        '/tmp/b.json',
    ]);
});


test('scp uses a password and passes SSHPASS', function () {
    $file = tempnam(sys_get_temp_dir(), 'scp-password-');

    if ($file === false) {
        throw new RuntimeException('could not create password file');
    }

    file_put_contents($file, 'secret');
    $shell = new FakeShell(fn (): ShellResult => new ShellResult(0, '', ''));
    $scp = new Scp(DeployFixtures::target(auth: new SshAuth(SshAuth::TYPE_PASS, $file)), $shell);

    try {
        $scp->upload('/tmp/a', '/srv/a');

        expect($shell->calls[0]['command'])->toContain('sshpass')
            ->and($shell->calls[0]['command'])->toContain('-e')
            ->and($shell->calls[0]['env'])->toBe(['SSHPASS' => 'secret']);
    } finally {
        unlink($file);
    }
});


test('remote files reads the current release state', function () {
    $shell = new FakeShell(fn (): ShellResult => new ShellResult(0, json_encode([
        'releaseId' => 'release-1',
        'status' => 'current',
    ], JSON_THROW_ON_ERROR), ''));

    $remote = new RemoteFiles(DeployFixtures::target(), new Ssh($shell));

    expect($remote->readCurrent())->toBe([
        'releaseId' => 'release-1',
        'status' => 'current',
    ])->and($remote->currentReleaseId())->toBe('release-1');
});


test('remote files treats a missing current state as null', function () {
    $shell = new FakeShell(fn (): ShellResult => new ShellResult(0, '__MISSING__', ''));
    $remote = new RemoteFiles(DeployFixtures::target(), new Ssh($shell));

    expect($remote->readCurrent())->toBeNull()
        ->and($remote->currentReleaseId())->toBeNull();
});


test('remote files writes current.json through ssh stdin', function () {
    $shell = new FakeShell(fn (): ShellResult => new ShellResult(0, '', ''));
    $remote = new RemoteFiles(DeployFixtures::target(), new Ssh($shell));

    $manifest = new ReleaseManifest(
        'release-1',
        'sha256:abc',
        '1.2.3',
        'version',
        '2026-01-01T00:00:00+00:00',
    );

    $remote->writeCurrent($manifest, '20260101-000000', 'current', 'release-0', 'staging');

    $stdin = $shell->calls[0]['stdin'];

    expect($stdin)->toBeString();

    $data = json_decode((string) $stdin, true, 512, JSON_THROW_ON_ERROR);

    expect($data)->toMatchArray([
        'releaseId' => 'release-1',
        'contentHash' => 'sha256:abc',
        'version' => '1.2.3',
        'status' => 'current',
        'previousReleaseId' => 'release-0',
        'backup' => '20260101-000000',
        'promotedFrom' => 'staging',
    ]);
});
