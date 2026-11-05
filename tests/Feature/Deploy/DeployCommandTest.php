<?php declare(strict_types=1);

use Tests\Feature\Deploy\DeployFixtures;
use Tool\Cli\Input;
use Tool\Contract\CommandInterface;
use Tool\Contract\ExitCode;
use Tool\Deploy\Command\CheckCommand;
use Tool\Deploy\Command\InitCommand;
use Tool\Deploy\Command\PushCommand;
use Tool\Deploy\Command\RollbackCommand;


function deployCommandInput(CommandInterface $command, array $tokens): Input
{
    return Input::parse($tokens, $command->options());
}


function deployCommandWorkspace(string $root): void
{
    file_put_contents($root .'/deploy.json', json_encode([
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


test('init command creates the deploy layout', function () {
    $root = sys_get_temp_dir() .'/deploy-command-init-'. bin2hex(random_bytes(6));
    mkdir($root .'/tools/deploy', 0700, true);
    file_put_contents($root .'/tools/deploy/deploy.example.json', '{"targets":{}}');

    $command = new InitCommand($root);

    expect($command->run(deployCommandInput($command, []), DeployFixtures::output()))
        ->toBe(ExitCode::Success->value)
        ->and(is_dir($root .'/.deploy/ssh'))->toBeTrue()
        ->and(is_file($root .'/deploy.json'))->toBeTrue();
});


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

    expect($command->run(deployCommandInput($command, ['--target=staging']), DeployFixtures::output()))
        ->toBe(ExitCode::Success->value)
        ->and(DeployFixtures::calls($shell))->toContain('composer install');
});


test('rollback command lists backups', function () {
    $root = DeployFixtures::workspace();
    deployCommandWorkspace($root);

    $stream = fopen('php://memory', 'w+');

    if ($stream === false) {
        throw new RuntimeException('could not open stream');
    }

    $shell = DeployFixtures::shell(snapshotList: "/srv/app/.deploy/backups/staging/20240101-000000/\n");
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


test('deploy tool registers its commands', function () {
    $tool = new Tool\Deploy\DeployTool();

    expect(array_keys($tool->commands(new STDW\Container\Container())))->toBe([
        'deploy init',
        'deploy check',
        'deploy push',
        'deploy rollback',
    ]);
});
