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
): FakeShell {
    return new FakeShell(function (array $command, ?string $stdin = null, ?string $cwd = null) use (
        $failWebrootRsync,
        $remoteEnv,
        $snapshotList,
        $hasRsync,
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
                return new ShellResult(0, json_encode([
                    'target' => 'staging',
                    'root' => '/srv/app',
                    'webroot' => '/srv/app/public_html',
                ], JSON_THROW_ON_ERROR), '');
            }

            if (str_contains($remote, 'sha256sum'))
            {
                return new ShellResult(0, hash('sha256', (string) $stdin) .'  /srv/app/application/env.json', '');
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

            return new ShellResult(0, str_ends_with($url, '/missing') ? '404' : '200', '');
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
