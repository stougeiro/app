<?php declare(strict_types=1);

use Sourcerer\Tool\Code\Deployer;
use Sourcerer\Tool\Code\ShellResult;
use Tests\Feature\Code\DeployFixtures;
use Tests\Feature\Code\FakeShell;


function deployDeployerWorkspace(): string
{
    $root = sys_get_temp_dir() .'/deploy-deployer-'. bin2hex(random_bytes(6));

    foreach ([
        'application/Config',
        'public/assets',
        '.sourcerer/code/override/staging/application',
        '.sourcerer/code/override/staging/public',
    ] as $directory)
    {
        mkdir($root .'/'. $directory, 0700, true);
    }

    file_put_contents($root .'/composer.json', '{}');
    file_put_contents($root .'/composer.lock', '{}');
    file_put_contents($root .'/application/Config/app.php', '<?php return [];');
    file_put_contents($root .'/public/index.php', '<?php');
    file_put_contents($root .'/public/assets/app.css', 'body{}');
    file_put_contents($root .'/.sourcerer/code/override/staging/public/.htaccess', 'Options -Indexes');
    file_put_contents($root .'/.sourcerer/code/override/staging/application/env.json', '{"environment":"staging"}');

    return $root;
}


function deployDeployerShell(
    bool $failWebrootRsync = false,
    string $snapshotList = '',
    bool $hasRsync = true,
    string $rootStatus = '200',
    string $missingStatus = '404',
    string $rootBody = '',
    ?string $manifest = null,
    bool $failComposer = false,
    bool $composerNoVendor = false,
): FakeShell {
    return new FakeShell(function (array $command, ?string $stdin = null, ?string $cwd = null) use (
        $failWebrootRsync,
        $snapshotList,
        $hasRsync,
        $rootStatus,
        $missingStatus,
        $rootBody,
        $manifest,
        $failComposer,
        $composerNoVendor,
    ): ShellResult {
        $binary = $command[0];
        $remote = $command[count($command) - 1];

        if ($binary === 'tar' && in_array('-czf', $command, true))
        {
            $target = $command[array_search('-czf', $command, true) + 1] ?? null;

            if (is_string($target))
            {
                @file_put_contents($target, '');
            }

            return new ShellResult(0, '', '');
        }

        if ($binary === 'ssh' && (str_contains($remote, 'hash_file') || str_contains($remote, 'sha256sum') || str_contains($remote, 'shasum') || str_contains($remote, 'openssl dgst')))
        {
            return new ShellResult(0, hash('sha256', ''), '');
        }

        if ($binary === 'ssh')
        {
            if (str_contains($remote, 'command -v rsync'))
            {
                return new ShellResult(0, $hasRsync ? 'yes' : 'no', '');
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

            if (str_contains($url, '_sr-check'))
            {
                return new ShellResult(0, json_encode([
                    'php' => '8.2.20',
                    'extensions' => ['pdo', 'pdo_sqlite'],
                ], JSON_THROW_ON_ERROR), '');
            }

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


test('check accepts a reachable target without probing php cli', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell();
    $deployer = deployTestDeployer($root, DeployFixtures::target(), $shell);

    $deployer->check();

    expect($shell->calls)->not->toBeEmpty()
        ->and(deployDeployerCalls($shell))->not->toContain('PHP_VERSION')
        ->and(deployDeployerCalls($shell))->not->toContain('application/env.json');
});


test('check creates only the configured root dir when missing', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell();
    $target = DeployFixtures::target(
        root: '/srv/app/development',
        rootBase: '/srv/app',
        rootDir: 'development',
    );
    $deployer = deployTestDeployer($root, $target, $shell);

    $deployer->check();

    expect(deployDeployerCalls($shell))
        ->toContain("mkdir '/srv/app/development'")
        ->toContain("test -d '/srv/app'");
});


test('check does not create a root when dir is omitted', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell();
    $deployer = deployTestDeployer($root, DeployFixtures::target(), $shell);

    $deployer->check();

    expect(deployDeployerCalls($shell))->not->toContain('mkdir');
});


test('push requires a local env override', function () {
    $root = deployDeployerWorkspace();
    unlink($root .'/.sourcerer/code/override/staging/application/env.json');
    $shell = deployDeployerShell();
    $deployer = deployTestDeployer($root, DeployFixtures::target(), $shell);

    expect(fn () => $deployer->push())
        ->toThrow(RuntimeException::class, 'missing env');

    expect(deployDeployerCalls($shell))->not->toContain('composer install');
});



test('push creates a backup, transfers the build and applies overrides', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell();
    $deployer = deployTestDeployer($root, DeployFixtures::target(), $shell);

    $deployer->push();

    $calls = deployDeployerCalls($shell);

    expect($calls)
        ->toContain('root.tar.gz')
        ->toContain('webroot.tar.gz')
        ->toContain('composer install')
        ->toContain('storage/upload')
        ->toContain('/public/')
        ->toContain('.sourcerer/code/override/staging/application/')
        ->toContain('.sourcerer/code/override/staging/public/');
});


test('push restores the backup when the transfer fails', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(failWebrootRsync: true);
    $deployer = deployTestDeployer($root, DeployFixtures::target(), $shell);

    expect(fn () => $deployer->push())
        ->toThrow(RuntimeException::class, 'rsync boom');

    expect(deployDeployerCalls($shell))
        ->toContain('manifest.json')
        ->toContain('root.tar.gz')
        ->toContain('webroot.tar.gz');
});


test('push fails when the root url does not return 200', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(rootStatus: '500');
    $deployer = deployTestDeployer($root, DeployFixtures::target(), $shell);

    expect(fn () => $deployer->push())
        ->toThrow(RuntimeException::class, 'returned 500');

    expect(deployDeployerCalls($shell))->toContain('manifest.json');
});


test('push fails when the response contains a fatal error', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(rootBody: 'Fatal error: boom');
    $deployer = deployTestDeployer($root, DeployFixtures::target(), $shell);

    expect(fn () => $deployer->push())
        ->toThrow(RuntimeException::class, 'fatal error');
});


test('push fails when the missing url does not return 404', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(missingStatus: '200');
    $deployer = deployTestDeployer($root, DeployFixtures::target(), $shell);

    expect(fn () => $deployer->push())
        ->toThrow(RuntimeException::class, 'did not return 404');
});


test('push fails when composer cannot build the vendor directory', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(failComposer: true);
    $deployer = deployTestDeployer($root, DeployFixtures::target(), $shell);

    expect(fn () => $deployer->push())
        ->toThrow(RuntimeException::class, 'composer failed');
});


test('push fails when composer does not create the vendor directory', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(composerNoVendor: true);
    $deployer = deployTestDeployer($root, DeployFixtures::target(), $shell);

    expect(fn () => $deployer->push())
        ->toThrow(RuntimeException::class, 'did not generate the vendor directory');
});


test('push falls back to tar and applies tar overrides', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(hasRsync: false);
    $deployer = deployTestDeployer($root, DeployFixtures::target(), $shell);

    $deployer->push();

    expect(deployDeployerCalls($shell))
        ->toContain('tar -czf')
        ->toContain('scp')
        ->toContain('incoming-root')
        ->toContain('incoming-webroot')
        ->toContain('.sourcerer/code/override/staging/public');
});


test('push excludes nested target webroots', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell();
    $target = DeployFixtures::target(nestedWebroots: ['dev']);
    $deployer = deployTestDeployer($root, $target, $shell);

    $deployer->push();

    expect(deployDeployerCalls($shell))
        ->toContain('--exclude=/dev')
        ->toContain("--exclude='./dev'");
});


test('backups lists remote snapshots', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(snapshotList: "/srv/app/.deploy/backups/20240102-000000/\n/srv/app/.deploy/backups/20240101-000000/\n");
    $deployer = deployTestDeployer($root, DeployFixtures::target(), $shell);

    expect($deployer->backups())->toBe(['20240102-000000', '20240101-000000']);
});


test('rollback fails without backups', function () {
    $root = deployDeployerWorkspace();
    $deployer = deployTestDeployer($root, DeployFixtures::target(), deployDeployerShell());

    expect(fn () => $deployer->rollback(null))
        ->toThrow(RuntimeException::class, 'no backup found');
});


test('rollback rejects an invalid timestamp', function () {
    $root = deployDeployerWorkspace();
    $deployer = deployTestDeployer(
        $root,
        DeployFixtures::target(),
        deployDeployerShell(snapshotList: "/srv/app/.deploy/backups/20240101-000000/\n"),
    );

    expect(fn () => $deployer->rollback('invalid'))
        ->toThrow(RuntimeException::class, 'invalid backup timestamp');
});


test('rollback rejects an unknown timestamp', function () {
    $root = deployDeployerWorkspace();
    $deployer = deployTestDeployer(
        $root,
        DeployFixtures::target(),
        deployDeployerShell(snapshotList: "/srv/app/.deploy/backups/20240101-000000/\n"),
    );

    expect(fn () => $deployer->rollback('20240102-000000'))
        ->toThrow(RuntimeException::class, 'backup not found');
});


test('rollback restores the latest backup by default', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(snapshotList: "/srv/app/.deploy/backups/20240101-000000/\n");
    $deployer = deployTestDeployer($root, DeployFixtures::target(), $shell);

    $deployer->rollback(null);

    expect(deployDeployerCalls($shell))
        ->toContain('ls -1dt')
        ->toContain('manifest.json')
        ->toContain('root.tar.gz')
        ->toContain('webroot.tar.gz');
});


test('rollback restores a specific timestamp', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(snapshotList: "/srv/app/.deploy/backups/20240102-000000/\n/srv/app/.deploy/backups/20240101-000000/\n");
    $deployer = deployTestDeployer($root, DeployFixtures::target(), $shell);

    $deployer->rollback('20240101-000000');

    expect(deployDeployerCalls($shell))->toContain('20240101-000000');
});


test('rollback preserves nested target webroots', function () {
    $root = deployDeployerWorkspace();
    $shell = deployDeployerShell(snapshotList: "/srv/app/.deploy/backups/20240101-000000/\n");
    $deployer = deployTestDeployer($root, DeployFixtures::target(nestedWebroots: ['dev']), $shell);

    $deployer->rollback(null);

    expect(deployDeployerCalls($shell))->toContain("! -name 'dev'");
});


test('rollback rejects a backup without a manifest', function () {
    $root = deployDeployerWorkspace();
    $deployer = deployTestDeployer(
        $root,
        DeployFixtures::target(),
        deployDeployerShell(
            snapshotList: "/srv/app/.deploy/backups/20240101-000000/\n",
            manifest: '__MISSING__',
        ),
    );

    expect(fn () => $deployer->rollback(null))
        ->toThrow(RuntimeException::class, 'snapshot manifest not found');
});


test('rollback rejects an invalid manifest', function () {
    $root = deployDeployerWorkspace();
    $deployer = deployTestDeployer(
        $root,
        DeployFixtures::target(),
        deployDeployerShell(
            snapshotList: "/srv/app/.deploy/backups/20240101-000000/\n",
            manifest: '{',
        ),
    );

    expect(fn () => $deployer->rollback(null))
        ->toThrow(RuntimeException::class, 'invalid snapshot manifest');
});


test('rollback rejects a manifest that is not an object', function () {
    $root = deployDeployerWorkspace();
    $deployer = deployTestDeployer(
        $root,
        DeployFixtures::target(),
        deployDeployerShell(
            snapshotList: "/srv/app/.deploy/backups/20240101-000000/\n",
            manifest: '123',
        ),
    );

    expect(fn () => $deployer->rollback(null))
        ->toThrow(RuntimeException::class, 'expected object');
});


test('rollback rejects a backup for another layout', function () {
    $root = deployDeployerWorkspace();
    $deployer = deployTestDeployer(
        $root,
        DeployFixtures::target(),
        deployDeployerShell(
            snapshotList: "/srv/app/.deploy/backups/20240101-000000/\n",
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


function deployTestDeployer(string $root, \Sourcerer\Tool\Code\Target $target, \Sourcerer\Tool\Code\Shell $shell): Deployer
{
    return new \Sourcerer\Tool\Code\Deployer($root, $target, $shell, DeployFixtures::output(), DeployFixtures::operator());
}


test('push stores a release manifest, event and current marker', function () {
    $root = DeployFixtures::workspace();
    $shell = DeployFixtures::shell();
    $deployer = new Deployer($root, DeployFixtures::target(), $shell, DeployFixtures::output(), DeployFixtures::operator());

    $deployer->push();

    $archives = glob($root .'/.sourcerer/code/releases/*/release.tar.gz');
    $manifests = glob($root .'/.sourcerer/code/releases/*/manifest.json');
    $events = glob($root .'/.sourcerer/code/events/*.json');
    $calls = DeployFixtures::calls($shell);
    $manifestData = json_decode((string) file_get_contents($manifests[0]), true);
    $eventData = json_decode((string) file_get_contents($events[0]), true);

    expect($archives)->toBe([])
        ->and($manifests)->not->toBeFalse()
        ->and($manifests)->toHaveCount(1)
        ->and($events)->not->toBeFalse()
        ->and($events)->toHaveCount(1)
        ->and($calls)->toContain('storage.tar.gz')
        ->and($calls)->toContain('current.json')
        ->and($calls)->toContain('release.tar.gz')
        ->and($calls)->toContain('--exclude=application/env.json')
        ->and($calls)->not->toContain('backups/staging')
        ->and($manifestData['artifactHash'] ?? null)->toBe('sha256:'. hash('sha256', ''))
        ->and($manifestData['createdBy']['name'] ?? null)->toBe('Test Operator')
        ->and($eventData['by']['name'] ?? null)->toBe('Test Operator')
        ->and($eventData['context']['artifactVerified'] ?? null)->toBeTrue()
        ->and($calls)->toContain('.deploy/releases')
        ->and($calls)->toContain('.deploy/events');
});


test('rollback restores storage when the backup contains it', function () {
    $root = DeployFixtures::workspace();
    $shell = DeployFixtures::shell(snapshotList: "/srv/app/.deploy/backups/20240101-000000/\n");
    $deployer = new Deployer($root, DeployFixtures::target(), $shell, DeployFixtures::output(), DeployFixtures::operator());

    $deployer->rollback(null);

    expect(DeployFixtures::calls($shell))
        ->toContain('storage.tar.gz')
        ->toContain('.sourcerer/code/override/staging/application/')
        ->toContain('application/env.json');
});
