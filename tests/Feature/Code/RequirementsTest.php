<?php declare(strict_types=1);

use Tests\Feature\Code\DeployFixtures;
use Tests\Feature\Code\FakeShell;
use Sourcerer\Tool\Code\Requirements\ComposerRequirements;
use Sourcerer\Tool\Code\Requirements\WebProbe;


test('reads php and extension requirements from composer.json', function () {
    $root = sys_get_temp_dir() .'/deploy-requirements-'. bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    file_put_contents($root .'/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'ext-pdo' => '*',
            'ext-pdo_sqlite' => '*',
        ],
        'require-dev' => [
            'ext-xdebug' => '*',
        ],
    ], JSON_THROW_ON_ERROR));

    $requirements = ComposerRequirements::fromFile($root .'/composer.json');

    expect($requirements->php)->toBe('>=8.2')
        ->and($requirements->extensions)->toBe(['pdo', 'pdo_sqlite']);

    $requirements->assert(['php' => '8.2.20', 'extensions' => ['pdo', 'pdo_sqlite']]);

    expect(fn () => $requirements->assert(['php' => '8.1.0', 'extensions' => ['pdo', 'pdo_sqlite']]))
        ->toThrow(RuntimeException::class, 'PHP >=8.2 required');

    expect(fn () => $requirements->assert(['php' => '8.2.20', 'extensions' => ['pdo']]))
        ->toThrow(RuntimeException::class, 'missing PHP extension pdo_sqlite');
});


test('web probe validates php and extensions and removes the probe', function () {
    $shell = new FakeShell(function (array $command, ?string $stdin = null): \Sourcerer\Tool\Code\ShellResult {
        $last = (string) $command[count($command) - 1];

        if (str_contains($last, '_sr-check') && $command[0] === 'curl') {
            return new \Sourcerer\Tool\Code\ShellResult(0, json_encode([
                'php' => '8.2.20',
                'extensions' => ['pdo', 'pdo_sqlite'],
            ], JSON_THROW_ON_ERROR), '');
        }

        return new \Sourcerer\Tool\Code\ShellResult(0, '', '');
    });

    $probe = (new WebProbe())->inspect(DeployFixtures::target(), $shell);
    $calls = DeployFixtures::calls($shell);

    expect($probe)->toBe(['php' => '8.2.20', 'extensions' => ['pdo', 'pdo_sqlite']])
        ->and($calls)->toContain('cat >')
        ->and($calls)->toContain('rm -f');
});
