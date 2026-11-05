<?php declare(strict_types=1);

use Tests\Feature\Cli\CliFixtures;
use Tests\Feature\Cli\RecordingCommand;
use Tool\Contract\ExitCode;
use Tool\Contract\Option;


function recordingCommands(): array
{
    return [
        'demo' => new RecordingCommand('demo', [
            new Option('name', 'A name', true),
            new Option('flag', 'A flag'),
        ]),
        'demo status' => new RecordingCommand('demo status', [
            new Option('name', 'A name', true),
        ]),
    ];
}


test('parses options with equals and space', function () {
    $equals = CliFixtures::run(recordingCommands(), ['demo', '--name=Ana', '--flag']);
    $space = CliFixtures::run(recordingCommands(), ['demo', '--name', 'Ana', '--flag']);

    expect($equals['code'])->toBe(0)
        ->and($equals['stdout'])->toContain('name=Ana')
        ->and($equals['stdout'])->toContain('flag=1')
        ->and($space['stdout'])->toBe($equals['stdout']);
});


test('resolves the longest command prefix', function () {
    $result = CliFixtures::run(recordingCommands(), ['demo', 'status', '--name=Ana']);

    expect($result['stdout'])->toContain('command=demo status')
        ->and($result['stdout'])->toContain('name=Ana');
});


test('collects positional arguments and stops option parsing after --', function () {
    $result = CliFixtures::run(recordingCommands(), ['demo', '--flag', '--', '--name', 'Ana']);

    expect($result['stdout'])->toContain('flag=1')
        ->and($result['stdout'])->toContain('args=--name,Ana');
});


test('shows global help and rejects command-level help', function () {
    $global = CliFixtures::run(recordingCommands(), []);
    $command = CliFixtures::run(recordingCommands(), ['demo', 'status', '--help']);

    expect($global['stdout'])->toContain('Tools:')
        ->and($global['stdout'])->toContain('demo')
        ->and($command['code'])->toBe(ExitCode::Invalid->value)
        ->and($command['stderr'])->toContain('unknown option');
});


test('shows the version', function () {
    $result = CliFixtures::run(recordingCommands(), ['--version']);

    expect($result['stdout'])->toContain('1.2.3');
});


test('rejects unknown commands and options', function () {
    $command = CliFixtures::run(recordingCommands(), ['nope']);
    $option = CliFixtures::run(recordingCommands(), ['demo', '--nope']);
    $missing = CliFixtures::run(recordingCommands(), ['demo', '--name']);

    expect($command['code'])->toBe(ExitCode::Invalid->value)
        ->and($command['stderr'])->toContain('unknown command')
        ->and($option['code'])->toBe(ExitCode::Invalid->value)
        ->and($option['stderr'])->toContain('unknown option')
        ->and($missing['code'])->toBe(ExitCode::Invalid->value)
        ->and($missing['stderr'])->toContain('requires a value');
});



test('returns the command exit code', function () {
    $commands = ['demo' => new RecordingCommand('demo', [], ExitCode::CheckFailed->value)];
    $result = CliFixtures::run($commands, ['demo']);

    expect($result['code'])->toBe(ExitCode::CheckFailed->value);
});


test('returns failure when a command throws', function () {
    $commands = [
        'boom' => new class implements Tool\Contract\CommandInterface {
            public function name(): string
            {
                return 'boom';
            }

            public function description(): string
            {
                return 'boom';
            }

            public function usage(): string
            {
                return 'sourcerer boom';
            }

            public function options(): array
            {
                return [];
            }

            public function run(Tool\Contract\InputInterface $input, Tool\Contract\OutputInterface $output): int
            {
                throw new RuntimeException('boom');
            }
        },
    ];

    $result = CliFixtures::run($commands, ['boom']);

    expect($result['code'])->toBe(ExitCode::Failure->value)
        ->and($result['stderr'])->toContain('boom');
});


test('shows help for a command group', function () {
    $commands = ['demo status' => new RecordingCommand('demo status', [new Option('name', 'A name', true)])];
    $result = CliFixtures::run($commands, ['demo']);

    expect($result['stdout'])->toContain('status')
        ->and($result['stdout'])->toContain('Usage:')
        ->and($result['stdout'])->toContain('--name=<value>')
        ->and($result['stdout'])->toContain('recording command');
});


test('help and version are imperative and must be alone', function () {
    $help = CliFixtures::run(recordingCommands(), ['--help', 'demo']);
    $version = CliFixtures::run(recordingCommands(), ['--version', 'demo']);
    $group = CliFixtures::run(recordingCommands(), ['demo', '--version']);

    expect($help['code'])->toBe(ExitCode::Invalid->value)
        ->and($help['stderr'])->toContain('--help must be used alone')
        ->and($version['code'])->toBe(ExitCode::Invalid->value)
        ->and($version['stderr'])->toContain('--version must be used alone')
        ->and($group['stdout'])->toContain('demo 1.2.3');
});
