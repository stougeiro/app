<?php declare(strict_types=1);

use Sourcerer\Tool\Code\Shell;


test('runs a command and captures stdout, stderr and exit code', function () {
    $result = (new Shell())->run([
        PHP_BINARY,
        '-r',
        'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(3);',
    ]);

    expect($result->code)->toBe(3)
        ->and($result->stdout)->toBe('out')
        ->and($result->stderr)->toBe('err');
});


test('passes stdin, cwd and env to the command', function () {
    $directory = realpath(sys_get_temp_dir());

    $result = (new Shell())->run(
        [PHP_BINARY, '-r', 'echo stream_get_contents(STDIN) ."|". getcwd() ."|". getenv("FOO");'],
        'hello',
        $directory === false ? null : $directory,
        ['FOO' => 'bar'],
    );

    expect($result->output())->toBe('hello|'. $directory .'|bar');
});


test('mustRun returns a successful result', function () {
    $result = (new Shell())->mustRun([PHP_BINARY, '-r', 'echo "ok";']);

    expect($result->output())->toBe('ok');
});


test('mustRun throws on failure', function () {
    expect(fn () => (new Shell())->mustRun([
        PHP_BINARY,
        '-r',
        'fwrite(STDERR, "boom"); exit(2);',
    ]))->toThrow(RuntimeException::class, 'command failed (2)');
});


test('quotes shell arguments', function () {
    expect(Shell::quote('a b'))->toBe("'a b'");
});
