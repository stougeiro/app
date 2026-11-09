<?php declare(strict_types=1);

use Tests\Feature\Code\DeployFixtures;
use Tests\Feature\Cli\CliFixtures;
use Tests\Feature\Code\FakeShell;
use Sourcerer\Tool\Manager\Input;
use Sourcerer\Tool\Contract\CommandInterface;
use Sourcerer\Tool\Contract\ExitCode;
use Sourcerer\Tool\Code\Command\CheckCommand;
use Sourcerer\Tool\Code\Command\PushCommand;
use Sourcerer\Tool\Code\Command\RollbackCommand;


function deployCommandInput(CommandInterface $command, array $tokens): Input
{
    return Input::parse($tokens, $command->options());
}


function deployCommandWorkspace(string $root): void
{
    if ( ! is_dir($root .'/.sourcerer/code')) {
        mkdir($root .'/.sourcerer/code', 0700, true);
    }
    file_put_contents($root .'/.sourcerer/code/config.json', json_encode([
        'targets' => [
            'staging' => [
                'ssh' => ['host' => 'staging.example.com', 'user' => 'deploy'],
                'root' => ['base' => '/srv/app'],
                'webroot' => '/srv/app/public_html',
                'url' => 'https://staging.example.com',
            ],
        ],
    ], JSON_THROW_ON_ERROR));
}


function deployCommandRead(mixed $stream): string
{
    rewind($stream);

    return (string) stream_get_contents($stream);
}


test('check command uses the injected shell and target option', function () {
    $root = DeployFixtures::workspace();
    deployCommandWorkspace($root);

    $shell = DeployFixtures::shell();
    $command = new CheckCommand($root, $shell);

    expect($command->run(deployCommandInput($command, ['--target=staging']), DeployFixtures::output()))
        ->toBe(ExitCode::Success->value)
        ->and($shell->calls)->not->toBeEmpty();
});


test('push command uses the injected shell', function () {
    $root = DeployFixtures::workspace();
    deployCommandWorkspace($root);

    $shell = DeployFixtures::shell();
    $command = new PushCommand($root, $shell);
    $stream = fopen('php://memory', 'w+');

    if ($stream === false) {
        throw new RuntimeException('could not open stream');
    }

    expect($command->run(deployCommandInput($command, ['--target=staging']), DeployFixtures::output($stream)))
        ->toBe(ExitCode::Success->value)
        ->and(DeployFixtures::calls($shell))->toContain('composer install')
        ->and(deployCommandRead($stream))->toContain('[staging] sync ok');
});


test('rollback command lists backups', function () {
    $root = DeployFixtures::workspace();
    deployCommandWorkspace($root);

    $stream = fopen('php://memory', 'w+');

    if ($stream === false) {
        throw new RuntimeException('could not open stream');
    }

    $shell = DeployFixtures::shell(snapshotList: "/srv/app/.deploy/backups/20240101-000000/\n");
    $command = new RollbackCommand($root, $shell);

    $code = $command->run(deployCommandInput($command, ['--target=staging', '--list']), DeployFixtures::output($stream));

    expect($code)->toBe(ExitCode::Success->value)
        ->and(deployCommandRead($stream))->toContain('backups:')
        ->and(deployCommandRead($stream))->toContain('20240101-000000');
});


test('rollback command rejects list together with timestamp', function () {
    $root = DeployFixtures::workspace();
    deployCommandWorkspace($root);

    $command = new RollbackCommand($root, DeployFixtures::shell());

    expect(fn () => $command->run(
        deployCommandInput($command, ['--target=staging', '--list', '--timestamp=20240101-000000']),
        DeployFixtures::output(),
    ))->toThrow(RuntimeException::class, '--list and --timestamp cannot be used together');
});


test('code tool registers its commands', function () {
    $tool = new Sourcerer\Tool\Code\CodeTool();

    expect(array_keys($tool->commands(sys_get_temp_dir())))->toBe([
        'code check',
        'code push',
        'code promote',
        'code releases',
        'code rollback',
    ]);
});


function deployPromoteWorkspace(): string
{
    $root = DeployFixtures::workspace();

    if ( ! is_dir($root .'/.sourcerer/code')) {
        mkdir($root .'/.sourcerer/code', 0700, true);
    }
    file_put_contents($root .'/.sourcerer/code/config.json', json_encode([
        'targets' => [
            'development' => [
                'ssh' => ['host' => 'example.com', 'user' => 'deploy'],
                'root' => ['base' => '/srv/dev/app'],
                'webroot' => '/srv/dev/public_html',
                'url' => 'https://dev.example.com',
            ],
            'staging' => [
                'ssh' => ['host' => 'example.com', 'user' => 'deploy'],
                'root' => ['base' => '/srv/staging/app'],
                'webroot' => '/srv/staging/public_html',
                'url' => 'https://staging.example.com',
            ],
            'production' => [
                'ssh' => ['host' => 'example.com', 'user' => 'deploy'],
                'root' => ['base' => '/srv/prod/app'],
                'webroot' => '/srv/prod/public_html',
                'url' => 'https://prod.example.com',
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    return $root;
}


function deployPromoteShell(string $currentJson, string $manifestJson): FakeShell
{
    return new FakeShell(function (array $command) use ($currentJson, $manifestJson): \Sourcerer\Tool\Code\ShellResult {
        $binary = $command[0];
        $last = (string) $command[count($command) - 1];

        if ($binary === 'ssh') {
            if (str_contains($last, '/srv/dev/app/.deploy/current.json')) {
                return new \Sourcerer\Tool\Code\ShellResult(0, $currentJson, '');
            }

            if (str_contains($last, '/srv/staging/app/.deploy/current.json')) {
                return new \Sourcerer\Tool\Code\ShellResult(0, '__MISSING__', '');
            }

            if (str_contains($last, 'application/env.json')) {
                return new \Sourcerer\Tool\Code\ShellResult(0, 'yes', '');
            }

            if (str_contains($last, 'command -v rsync')) {
                return new \Sourcerer\Tool\Code\ShellResult(0, 'yes', '');
            }

            return new \Sourcerer\Tool\Code\ShellResult(0, '', '');
        }

        if ($binary === 'scp') {
            $local = (string) $command[count($command) - 1];

            if (str_contains($local, ':')) {
                return new \Sourcerer\Tool\Code\ShellResult(0, '', '');
            }

            if (str_contains($local, 'release.tar.gz')) {
                file_put_contents($local, 'release');
            } else {
                file_put_contents($local, $manifestJson);
            }

            return new \Sourcerer\Tool\Code\ShellResult(0, '', '');
        }

        if ($binary === 'curl') {
            if (str_contains($last, '_sr-check')) {
                return new \Sourcerer\Tool\Code\ShellResult(0, json_encode([
                    'php' => '8.2.20',
                    'extensions' => ['pdo', 'pdo_sqlite'],
                ], JSON_THROW_ON_ERROR), '');
            }

            if ($last === 'https://dev.example.com') {
                return new \Sourcerer\Tool\Code\ShellResult(0, '302 https://staging.example.com', '');
            }

            if (str_ends_with($last, '/missing')) {
                return new \Sourcerer\Tool\Code\ShellResult(0, '404', '');
            }

            return new \Sourcerer\Tool\Code\ShellResult(0, '200', '');
        }

        return new \Sourcerer\Tool\Code\ShellResult(0, '', '');
    });
}


test('promote moves the current release to the next target and retires the source', function () {
    $root = deployPromoteWorkspace();
    $releaseId = 'abcdef123456-20261105T102030';
    $contentHash = 'sha256:abcdef1234567890';

        $manifest = new \Sourcerer\Tool\Code\Release\ReleaseManifest(
        $releaseId,
        $contentHash,
        '1.0.0',
        'version',
        gmdate('c'),
        null,
        null,
        ['name' => 'Test Operator', 'email' => 'test@example.com'],
    );

    $manifestJson = json_encode($manifest->toArray(), JSON_THROW_ON_ERROR);
    (new \Sourcerer\Tool\Code\Release\ReleaseStore($root))->persist($manifest);

    $current = json_encode([
        'releaseId' => $releaseId,
        'contentHash' => $contentHash,
        'version' => '1.0.0',
        'status' => 'current',
    ], JSON_THROW_ON_ERROR);

    $shell = deployPromoteShell($current, $manifestJson);

    $stream = fopen('php://memory', 'w+');

    if ($stream === false) {
        throw new RuntimeException('could not open stream');
    }

    $command = new \Sourcerer\Tool\Code\Command\PromoteCommand($root, $shell);
    $code = $command->run(
        deployCommandInput($command, ['--from=development']),
        DeployFixtures::output($stream),
    );

    $calls = DeployFixtures::calls($shell);

    expect($code)->toBe(ExitCode::Success->value)
        ->and(deployCommandRead($stream))->toContain('promoted')
        ->and($calls)->toContain('/srv/staging/app/.deploy/releases/'. $releaseId)
        ->and($calls)->toContain('/srv/staging/app/.deploy/current.json')
        ->and($calls)->toContain('/srv/dev/public_html/index.php')
        ->and($calls)->toContain('/srv/dev/public_html/.htaccess')
        ->and($calls)->toContain("rm -rf '/srv/dev/app/application'")
        ->and($calls)->toContain('https://dev.example.com');
});


test('retire aborts when the source has nested webroots', function () {
    $root = DeployFixtures::workspace();
    $promoter = new \Sourcerer\Tool\Code\Promotion\Promoter(
        $root,
        DeployFixtures::target(nestedWebroots: ['dev']),
        DeployFixtures::shell(),
        DeployFixtures::output(),
        DeployFixtures::operator(),
    );

    expect(fn () => $promoter->retire('https://staging.example.com', 'staging'))
        ->toThrow(RuntimeException::class, 'nested webroots');
});


function deployReleasesInput(CommandInterface $command, array $tokens): Input
{
    return Input::parse($tokens, $command->options());
}


function deployReleasesWorkspace(): string
{
    $root = deployPromoteWorkspace();
    $releaseId = 'abcdef123456-20261105T102030';

    (new \Sourcerer\Tool\Code\Release\ReleaseStore($root))->persist(
        new \Sourcerer\Tool\Code\Release\ReleaseManifest(
            $releaseId,
            'sha256:abcdef1234567890',
            '1.0.0',
            'version',
            '2026-11-05T10:20:00Z',
            null,
            null,
            ['name' => 'Sidney', 'email' => 'sidney@example.com'],
        ),
    );

    $events = $root .'/.sourcerer/code/events';
    mkdir($events, 0700, true);

    foreach ([
        ['action' => 'push', 'target' => 'development', 'from' => null, 'to' => 'development', 'at' => '2026-11-05T10:22:00Z'],
        ['action' => 'promote', 'target' => 'staging', 'from' => 'development', 'to' => 'staging', 'at' => '2026-11-05T10:30:00Z'],
    ] as $event) {
        file_put_contents(
            $events .'/'. $event['at'] .'-'. $event['target'] .'.json',
            json_encode($event + [
                'releaseId' => $releaseId,
                'by' => ['name' => 'Sidney', 'email' => 'sidney@example.com'],
                'backup' => null,
                'status' => 'ok',
            ], JSON_THROW_ON_ERROR),
        );
    }

    return $root;
}


test('releases command reads local history without ssh', function () {
    $root = deployReleasesWorkspace();
    $stream = fopen('php://memory', 'w+');

    if ($stream === false) {
        throw new RuntimeException('could not open stream');
    }

    $command = new \Sourcerer\Tool\Code\Command\ReleasesCommand($root);
    $code = $command->run(
        deployReleasesInput($command, []),
        DeployFixtures::output($stream),
    );

    $out = deployCommandRead($stream);

    expect($code)->toBe(ExitCode::Success->value)
        ->and($out)->toContain('abcdef123456-20261105T102030')
        ->and($out)->toContain('Sidney <sidney@example.com>')
        ->and($out)->toContain('push')
        ->and($out)->toContain('promote');
});


test('releases command filters local history by target', function () {
    $root = deployReleasesWorkspace();
    $stream = fopen('php://memory', 'w+');

    if ($stream === false) {
        throw new RuntimeException('could not open stream');
    }

    $command = new \Sourcerer\Tool\Code\Command\ReleasesCommand($root);
    $command->run(
        deployReleasesInput($command, ['--target=staging']),
        DeployFixtures::output($stream),
    );

    $out = deployCommandRead($stream);

    expect($out)->toContain('promote')
        ->and($out)->toContain('development -> staging')
        ->and($out)->not->toContain('push    development');
});


test('releases sync runs all targets and rejects target combination', function () {
    $root = deployReleasesWorkspace();
    $stream = fopen('php://memory', 'w+');

    if ($stream === false) {
        throw new RuntimeException('could not open stream');
    }

    $shell = new FakeShell(fn (): \Sourcerer\Tool\Code\ShellResult => new \Sourcerer\Tool\Code\ShellResult(0, '', ''));
    $command = new \Sourcerer\Tool\Code\Command\ReleasesCommand($root, $shell);

    $code = $command->run(
        deployReleasesInput($command, ['--sync']),
        DeployFixtures::output($stream),
    );

    $out = deployCommandRead($stream);

    expect($code)->toBe(ExitCode::Success->value)
        ->and($out)->toContain('[development] sync ok')
        ->and($out)->toContain('[staging] sync ok')
        ->and($out)->toContain('[production] sync ok');

    expect(fn () => $command->run(
        deployReleasesInput($command, ['--sync', '--target=staging']),
        DeployFixtures::output(),
    ))->toThrow(RuntimeException::class, '--sync cannot be used');
});



test('releases sync continues on errors and returns failure', function () {
    $root = deployReleasesWorkspace();
    $stream = fopen('php://memory', 'w+');

    if ($stream === false) {
        throw new RuntimeException('could not open stream');
    }

    $shell = new FakeShell(fn (array $command): \Sourcerer\Tool\Code\ShellResult => new \Sourcerer\Tool\Code\ShellResult(1, '', 'sync boom'));
    $command = new \Sourcerer\Tool\Code\Command\ReleasesCommand($root, $shell);

    $code = $command->run(
        deployReleasesInput($command, ['--sync']),
        DeployFixtures::output($stream),
    );

    $out = deployCommandRead($stream);

    expect($code)->toBe(ExitCode::Failure->value)
        ->and($out)->toContain('sync boom')
        ->and($out)->toContain('[production]');
});


test('deploy help exposes required target and from options', function () {
    $root = DeployFixtures::workspace();
    $group = CliFixtures::run(
        [
            'code promote' => new \Sourcerer\Tool\Code\Command\PromoteCommand($root),
            'code push' => new \Sourcerer\Tool\Code\Command\PushCommand($root),
            'code releases' => new \Sourcerer\Tool\Code\Command\ReleasesCommand($root),
        ],
        ['code'],
    );

    expect($group['stdout'])
        ->toContain('Usage:')
        ->toContain('--target=<target>')
        ->toContain('--from=<target>')
        ->toContain('(required)')
        ->toContain('--sync');
});


test('releases help shows usage without examples', function () {
    $root = DeployFixtures::workspace();
    $result = CliFixtures::run(
        ['code releases' => new \Sourcerer\Tool\Code\Command\ReleasesCommand($root)],
        ['code'],
    );

    expect($result['stdout'])
        ->toContain('sourcerer code releases [--target=<target>] | --sync')
        ->toContain('--sync')
        ->and($result['stdout'])->not->toContain('Examples:');
});


test('releases json option is no longer accepted', function () {
    $root = DeployFixtures::workspace();
    $result = CliFixtures::run(
        ['code releases' => new \Sourcerer\Tool\Code\Command\ReleasesCommand($root)],
        ['code', 'releases', '--json'],
    );

    expect($result['code'])->toBe(ExitCode::Invalid->value)
        ->and($result['stderr'])->toContain('unknown option: --json');
});
