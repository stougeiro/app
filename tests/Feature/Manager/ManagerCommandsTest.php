<?php declare(strict_types=1);

use Sourcerer\Tool\Code\CodeTool;
use Sourcerer\Tool\Contract\OperatorFile;
use Sourcerer\Tool\Manager\Command\InstallCommand;
use Sourcerer\Tool\Manager\Command\OperatorCommand;
use Sourcerer\Tool\Manager\Input;
use Sourcerer\Tool\Manager\Output;
use Sourcerer\Tool\Schema\SchemaTool;


function managerRoot(): string
{
    $root = sys_get_temp_dir() .'/sourcerer-manager-'. bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    return $root;
}


/** @return array{output: Output, stream: resource} */
function managerOutput(): array
{
    $stream = fopen('php://memory', 'w+');

    if ($stream === false) {
        throw new RuntimeException('could not open output stream');
    }

    return ['output' => new Output($stream), 'stream' => $stream];
}


/** @param resource $stream */
function managerRead($stream): string
{
    rewind($stream);

    return (string) stream_get_contents($stream);
}


test('operator command configures and shows the identity', function () {
    $root = managerRoot();
    $command = new OperatorCommand($root);

    $write = managerOutput();
    $code = $command->run(Input::parse(['--name=Test Operator', '--email=test@example.com'], $command->options()), $write['output']);

    expect($code)->toBe(0)
        ->and(OperatorFile::read($root)?->name)->toBe('Test Operator')
        ->and(managerRead($write['stream']))->toContain('operator configured');

    $show = managerOutput();
    $command->run(Input::parse([], $command->options()), $show['output']);

    expect(managerRead($show['stream']))
        ->toContain('name: Test Operator')
        ->toContain('email: test@example.com');
});


test('install requires an operator', function () {
    $root = managerRoot();
    $command = new InstallCommand($root, [new CodeTool(), new SchemaTool()]);

    expect(fn () => $command->run(Input::parse([], $command->options()), managerOutput()['output']))
        ->toThrow(RuntimeException::class, 'operator not configured');
});


test('install calls each tool install and creates the control layout', function () {
    $root = managerRoot();
    OperatorFile::save($root, new Sourcerer\Tool\Contract\Operator('Test', 'test@example.com'));

    $command = new InstallCommand($root, [new CodeTool(), new SchemaTool()]);
    $fixture = managerOutput();

    expect($command->run(Input::parse([], $command->options()), $fixture['output']))->toBe(0)
        ->and(is_dir($root .'/.sourcerer/code/ssh'))->toBeTrue()
        ->and(is_file($root .'/.sourcerer/code/config.json'))->toBeTrue()
        ->and(is_dir($root .'/.sourcerer/schema/local/tmp'))->toBeTrue()
        ->and(is_file($root .'/.sourcerer/schema/config.json'))->toBeTrue()
        ->and(managerRead($fixture['stream']))->toContain('created .sourcerer/code');
});
