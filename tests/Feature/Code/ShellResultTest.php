<?php declare(strict_types=1);

use Tool\Deploy\ShellResult;


test('reports success and trimmed output', function () {
    $result = new ShellResult(0, "  hello\n", 'ignored');

    expect($result->ok())->toBeTrue()
        ->and($result->output())->toBe('hello');
});


test('reports failure', function () {
    $result = new ShellResult(2, '', 'boom');

    expect($result->ok())->toBeFalse()
        ->and($result->output())->toBe('');
});
