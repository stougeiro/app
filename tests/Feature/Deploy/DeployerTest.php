<?php declare(strict_types=1);

use Deploy\Deployer;
use Deploy\ShellResult;
use Tests\Feature\Deploy\DeployFixtures;
use Tests\Feature\Deploy\FakeShell;


function deployDeployerWorkspace(): string
{
    $root = sys_get_temp_dir() .'/deploy-deployer-'. bin2hex(random_bytes(6));

    foreach ([
        'application/Config',
        'public_html/assets',
        '.deploy/env',
        '.deploy/plans',
        '.deploy/override/staging/public_html',
    ] as $directory)
    {
        mkdir($root .'/'. $directory, 0700, true);
    }

    file_put_contents($root .'/composer.json', '{}');
    file_put_contents($root .'/composer.lock', '{}');
    file_put_contents($root .'/application/Config/app.php', '<?php return [];');
    file_put_contents($root .'/public_html/index.php', '<?php');
    file_put_contents($root .'/public_html/assets/app.css', 'body{}');
    file_put_contents($root .'/.deploy/override/staging/public_html/.htaccess', 'Options -Indexes');
    file_put_contents(
        $root .'/.deploy/env/staging.json',
        json_encode(['environment' => 'staging', 'APP_KEY' => 'abc'], JSON_THROW_ON_ERROR),
    );

    return $root;
}


function deployDeployerShell(
    bool $failWebrootRsync = false,
    ?string $remoteEnv = null,
    string $snapshotList = '',
    bool $hasRsync = true,
    string $rootStatus = '200',
    string $missingStatus = '404',
    string $rootBody = '',
    ?string $manifest = null,
    bool $failComposer = false,
    bool $composerNoVendor = false,
    bool $failEnvHash = false,
): FakeShell {
    return new FakeShell(function (array $command, ?string $stdin = null, ?string $cwd = null) use (
        $failWebrootRsync,
        $remoteEnv,
        $snapshotList,
        $hasRsync,
        $rootStatus,
        $missingStatus,
        $rootBody,
        $manifest,
        $failComposer,
        $composerNoVendor,
        $failEnvHash,
    ): ShellResult {
        $binary = $command[0];
        $remote = $command[count($command) - 1];

        if ($binary === 'ssh')
        {
            if (str_contains($remote, 'command -v rsync'))
            {
                return new ShellResult(0, $hasRsync ? 'yes' : 'no', '');
            }

            if (str_contains($remote, 'PHP_VERSION'))
            {
                return new ShellResult(0, '8.2.10', '');
            }

            if (str_contains($remote, 'then echo active'))
            {
                return new ShellResult(0, 'inactive', '');
            }

            if (str_contains($remote, 'ls -1dt'))
            {
                return new ShellResult(0, $snapshotList, '');
            }

            if (str_contains($remote, 'manifest.json'))
            {
                if ($manifest !== null)
                {
                    return new ShellResult(0, $manifest, '');
                }

                return new ShellResult(0, json_encode([
                    'target' => 'staging',
                    'root' => '/srv/app',
                    'webroot' => '/srv/app/public_html',
                ], JSON_THROW_ON_ERROR), '');
            }

            if (str_contains($remote, 'sha256sum'))
            {
                $hash = $failEnvHash ? str_repeat('0', 64) : hash('sha256', (string) $stdin);

                return new ShellResult(0, $hash .'  /srv/app/application/env.json', '');
            }

            if (str_contains($remote, 'env.json'))
            {
                return new ShellResult(0, $remoteEnv ?? '__MISSING__', '');
            }

            return new ShellResult(0, '', '');
        }

        if ($binary === 'composer')
        {
            if ($cwd === null)
            {
                return new ShellResult(1, '', 'missing cwd');
            }

            if ($failComposer)
            {
                return new ShellResult(1, '', 'composer boom');
            }

            if ($composerNoVendor)
            {
                return new ShellResult(0, '', '');
            }

            mkdir($cwd .'/vendor/composer', 0700, true);
            file_put_contents($cwd .'/vendor/autoload.php', '<?php');

            return new ShellResult(0, '', '');
        }

        if ($binary === 'rsync')
        {
            $joined = implode(' ', $command);

            if ($failWebrootRsync
                && str_contains($joined, '/public_html/')
                && ! str_contains($joined, '/override/'))
            {
                return new ShellResult(1, '', 'rsync boom');
            }

            return new ShellResult(0, '', '');
        }

        if ($binary === 'curl')
        {
            $url = $command[count($command) - 1];
            $status = str_ends_with($url, '/missing') ? $missingStatus : $rootStatus;

            if (in_array('-o', $command, true))
            {
                return new ShellResult(0, $status, '');
            }

            return new ShellResult(0, $rootBody, '');
        }

        return new ShellResult(0, '', '');
    });
}


function deployDeployerCalls(FakeShell $shell): string
{
    return implode("\n", array_map(
        static fn (array $call): string => implode(' ', $call['command']),
        $shell->calls,
    ));
}


test('check accepts a reachable target with a valid remote env', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(remoteEnv: '{"environment":"staging"}');
    $deployer = new Deployer($root, DeployFixtures::target(), $shell);

    $deployer->check();

    expect($shell->calls)->not->toBeEmpty();
});


test('plan writes a plan including env and override changes', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell();
    $deployer = new Deployer($root, DeployFixtures::target(), $shell);

    $deployer->plan(true);

    $file = $root .'/.deploy/plans/staging.json';
    $plan = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    expect($plan['target'])->toBe('staging')
        ->and($plan['withEnv'])->toBeTrue()
        ->and($plan['envHash'])->toBeString()
        ->and(deployDeployerCalls($shell))->toContain('--dry-run');
});


test('maintenance status reports the remote marker and http code', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell();
    $deployer = new Deployer($root, DeployFixtures::target(), $shell);

    $deployer->maintenanceStatus();

    expect(deployDeployerCalls($shell))
        ->toContain('echo active')
        ->toContain('curl');
});


test('push transfers build and overrides and writes env', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell();
    $deployer = new Deployer($root, DeployFixtures::target(), $shell);

    $deployer->push(true);

    $calls = deployDeployerCalls($shell);
    $rsync = array_values(array_filter(
        $shell->calls,
        static fn (array $call): bool => $call['command'][0] === 'rsync',
    ));

    expect($rsync)->toHaveCount(4)
        ->and($calls)->toContain('.maintenance')
        ->and($calls)->toContain('sha256sum')
        ->and($calls)->toContain('--exclude=/uploads/***');
});


test('check warns when a nested webroot lacks an override entrypoint', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(remoteEnv: '{"environment":"staging"}');
    $deployer = new Deployer(
        $root,
        DeployFixtures::target(root: '/srv/app/code', webroot: '/srv/app/public_html'),
        $shell,
    );

    $deployer->check();

    expect($shell->calls)->not->toBeEmpty();
});


test('plan previews the fallback when rsync is unavailable', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(hasRsync: false);
    $deployer = new Deployer($root, DeployFixtures::target(), $shell);

    $deployer->plan(false);

    expect(deployDeployerCalls($shell))->not->toContain('--dry-run');
});


test('push rejects a remote env for another environment', function () {
    $root = deployDeployerWorkspace();
    $deployer = new Deployer(
        $root,
        DeployFixtures::target(),
        deployDeployerShell(remoteEnv: '{"environment":"production"}'),
    );

    expect(fn () => $deployer->push(true))
        ->toThrow(RuntimeException::class, "env declares 'production'");
});


test('push fails when composer does not create the vendor directory', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(composerNoVendor: true);
    $deployer = new Deployer($root, DeployFixtures::target(), $shell);

    expect(fn () => $deployer->push(true))
        ->toThrow(RuntimeException::class, 'did not generate the vendor directory');
});


test('push fails when the remote env hash does not match after write', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(failEnvHash: true);
    $deployer = new Deployer($root, DeployFixtures::target(), $shell);

    expect(fn () => $deployer->push(true))
        ->toThrow(RuntimeException::class, 'does not match after write');
});


test('push fails when composer cannot build the vendor directory', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(failComposer: true);
    $deployer = new Deployer($root, DeployFixtures::target(), $shell);

    expect(fn () => $deployer->push(true))
        ->toThrow(RuntimeException::class, 'composer failed');
});


test('push fails when the local env is missing', function () {
    $root = deployDeployerWorkspace();
    unlink($root .'/.deploy/env/staging.json');
    $deployer = new Deployer($root, DeployFixtures::target(), deployDeployerShell());

    expect(fn () => $deployer->push(true))
        ->toThrow(RuntimeException::class, 'local env not found');
});


test('push rejects invalid remote env json', function () {
    $root = deployDeployerWorkspace();
    $deployer = new Deployer($root, DeployFixtures::target(), deployDeployerShell(remoteEnv: '{'));

    expect(fn () => $deployer->push(true))
        ->toThrow(RuntimeException::class, 'invalid remote env');
});


test('push rejects a remote env that is not an object', function () {
    $root = deployDeployerWorkspace();
    $deployer = new Deployer($root, DeployFixtures::target(), deployDeployerShell(remoteEnv: '"text"'));

    expect(fn () => $deployer->push(true))
        ->toThrow(RuntimeException::class, 'must be a JSON object');
});


test('push keeps maintenance active when backups are disabled and transfer fails', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(failWebrootRsync: true);
    $deployer = new Deployer($root, DeployFixtures::target(backupEnabled: false), $shell);

    expect(fn () => $deployer->push(true))
        ->toThrow(RuntimeException::class, 'rsync boom');

    expect(deployDeployerCalls($shell))
        ->not->toContain('root.tar.gz')
        ->not->toContain('manifest.json');
});


test('push skips the snapshot when backups are disabled', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell();
    $deployer = new Deployer($root, DeployFixtures::target(backupEnabled: false), $shell);

    $deployer->push(true);

    expect(deployDeployerCalls($shell))
        ->not->toContain('root.tar.gz')
        ->not->toContain('manifest.json');
});


test('push fails when the root url does not return 200', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(rootStatus: '500');
    $deployer = new Deployer($root, DeployFixtures::target(), $shell);

    expect(fn () => $deployer->push(true))
        ->toThrow(RuntimeException::class, 'returned 500');

    expect(deployDeployerCalls($shell))
        ->toContain('manifest.json')
        ->toContain('root.tar.gz');
});


test('push fails when the response contains a fatal error', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(rootBody: 'Fatal error: boom');
    $deployer = new Deployer($root, DeployFixtures::target(), $shell);

    expect(fn () => $deployer->push(true))
        ->toThrow(RuntimeException::class, 'fatal error');
});


test('push fails when the missing url does not return 404', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(missingStatus: '200');
    $deployer = new Deployer($root, DeployFixtures::target(), $shell);

    expect(fn () => $deployer->push(true))
        ->toThrow(RuntimeException::class, 'did not return 404');
});


test('push restores the snapshot when the transfer fails', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(failWebrootRsync: true);
    $deployer = new Deployer($root, DeployFixtures::target(), $shell);

    expect(fn () => $deployer->push(true))
        ->toThrow(RuntimeException::class, 'rsync boom');

    expect(deployDeployerCalls($shell))->toContain('manifest.json')
        ->and(deployDeployerCalls($shell))->toContain('root.tar.gz');
});


test('push requires env on first install', function () {
    $root = deployDeployerWorkspace();
    $deployer = new Deployer($root, DeployFixtures::target(), deployDeployerShell());

    expect(fn () => $deployer->push(false))
        ->toThrow(RuntimeException::class, 'remote env missing');
});


test('push falls back to tar and applies tar overrides', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(hasRsync: false);
    $deployer = new Deployer($root, DeployFixtures::target(), $shell);

    $deployer->push(true);

    expect(deployDeployerCalls($shell))
        ->toContain('tar -czf')
        ->toContain('scp')
        ->toContain('incoming-root')
        ->toContain('incoming-webroot');
});


test('rollback fails without snapshots', function () {
    $root = deployDeployerWorkspace();
    $deployer = new Deployer(
        $root,
        DeployFixtures::target(),
        deployDeployerShell(remoteEnv: '{"environment":"staging"}'),
    );

    expect(fn () => $deployer->rollback(null))
        ->toThrow(RuntimeException::class, 'no snapshot found');
});


test('rollback rejects an unknown snapshot', function () {
    $root = deployDeployerWorkspace();
    $deployer = new Deployer(
        $root,
        DeployFixtures::target(),
        deployDeployerShell(
            remoteEnv: '{"environment":"staging"}',
            snapshotList: "/srv/app/.deploy/backups/staging/20240101-000000/\n",
        ),
    );

    expect(fn () => $deployer->rollback('20240102-000000'))
        ->toThrow(RuntimeException::class, 'snapshot not found');
});


test('rollback restores the latest snapshot', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(
        remoteEnv: '{"environment":"staging"}',
        snapshotList: "/srv/app/.deploy/backups/staging/20240101-000000/\n",
    );
    $deployer = new Deployer($root, DeployFixtures::target(), $shell);

    $deployer->rollback(null);

    expect(deployDeployerCalls($shell))
        ->toContain('ls -1dt')
        ->toContain('manifest.json')
        ->toContain('root.tar.gz');
});


test('rollback rejects a snapshot without a manifest', function () {
    $root = deployDeployerWorkspace();
    $deployer = new Deployer(
        $root,
        DeployFixtures::target(),
        deployDeployerShell(
            remoteEnv: '{"environment":"staging"}',
            snapshotList: "/srv/app/.deploy/backups/staging/20240101-000000/\n",
            manifest: '__MISSING__',
        ),
    );

    expect(fn () => $deployer->rollback(null))
        ->toThrow(RuntimeException::class, 'snapshot manifest not found');
});


test('rollback rejects an invalid snapshot manifest', function () {
    $root = deployDeployerWorkspace();
    $deployer = new Deployer(
        $root,
        DeployFixtures::target(),
        deployDeployerShell(
            remoteEnv: '{"environment":"staging"}',
            snapshotList: "/srv/app/.deploy/backups/staging/20240101-000000/\n",
            manifest: '{',
        ),
    );

    expect(fn () => $deployer->rollback(null))
        ->toThrow(RuntimeException::class, 'invalid snapshot manifest');
});


test('rollback rejects a manifest that is not an object', function () {
    $root = deployDeployerWorkspace();
    $deployer = new Deployer(
        $root,
        DeployFixtures::target(),
        deployDeployerShell(
            remoteEnv: '{"environment":"staging"}',
            snapshotList: "/srv/app/.deploy/backups/staging/20240101-000000/\n",
            manifest: '123',
        ),
    );

    expect(fn () => $deployer->rollback(null))
        ->toThrow(RuntimeException::class, 'expected object');
});


test('rollback rejects a snapshot for another layout', function () {
    $root = deployDeployerWorkspace();
    $deployer = new Deployer(
        $root,
        DeployFixtures::target(),
        deployDeployerShell(
            remoteEnv: '{"environment":"staging"}',
            snapshotList: "/srv/app/.deploy/backups/staging/20240101-000000/\n",
            manifest: json_encode([
                'target' => 'staging',
                'root' => '/srv/other',
                'webroot' => '/srv/other/public_html',
            ], JSON_THROW_ON_ERROR),
        ),
    );

    expect(fn () => $deployer->rollback(null))
        ->toThrow(RuntimeException::class, 'layout does not match');
});
