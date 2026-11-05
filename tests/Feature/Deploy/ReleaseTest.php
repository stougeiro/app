<?php declare(strict_types=1);

use Tool\Deploy\Environment\EnvironmentChain;
use Tool\Deploy\Release\ReleaseHasher;
use Tool\Deploy\Release\ReleaseId;
use Tests\Feature\Deploy\FakeShell;
use Tool\Deploy\Release\ArtifactVerifier;
use Tool\Deploy\Retirement\RedirectStub;


function deployReleaseWorkspace(): string
{
    $root = sys_get_temp_dir() .'/deploy-release-'. bin2hex(random_bytes(6));

    mkdir($root .'/application/storage', 0700, true);
    mkdir($root .'/public', 0700, true);

    file_put_contents($root .'/composer.json', '{}');
    file_put_contents($root .'/composer.lock', '{}');
    file_put_contents($root .'/application/app.php', '<?php');
    file_put_contents($root .'/application/env.json', '{"environment":"dev"}');
    file_put_contents($root .'/application/storage/data.txt', 'data');
    file_put_contents($root .'/public/index.php', '<?php');
    file_put_contents($root .'/public/.maintenance', '');
    file_put_contents($root .'/public/style.css', 'body{}');

    return $root;
}


test('release hash ignores storage, env and maintenance files', function () {
    $root = deployReleaseWorkspace();
    $hasher = new ReleaseHasher();

    $first = $hasher->hash($root);

    file_put_contents($root .'/application/storage/data.txt', 'changed');
    file_put_contents($root .'/application/env.json', '{"environment":"prod"}');
    file_put_contents($root .'/public/.maintenance', '1');

    expect($hasher->hash($root))->toBe($first);

    file_put_contents($root .'/public/style.css', 'body{color:red}');

    expect($hasher->hash($root))->not->toBe($first);
});


test('release id uses hash prefix and timestamp', function () {
    $at = new DateTimeImmutable('2026-11-05 10:20:30', new DateTimeZone('UTC'));

    expect(ReleaseId::fromHash('sha256:abcdef1234567890', $at))
        ->toBe('abcdef123456-20261105T102030');
});


test('environment chain never skips stages', function () {
    $all = ['development', 'staging', 'production'];

    expect(EnvironmentChain::next('development', $all))->toBe('staging')
        ->and(EnvironmentChain::next('staging', $all))->toBe('production')
        ->and(EnvironmentChain::next('production', $all))->toBeNull()
        ->and(EnvironmentChain::next('development', ['development', 'production']))->toBe('production')
        ->and(EnvironmentChain::next('development', ['development']))->toBeNull()
        ->and(EnvironmentChain::next('demonstration', $all))->toBeNull()
        ->and(EnvironmentChain::next('staging', ['staging', 'production', 'demonstration']))->toBe('production');
});


test('redirect stub falls back to tool defaults', function () {
    $root = sys_get_temp_dir() .'/deploy-stub-'. bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    $stub = (new RedirectStub($root))->render('https://staging.example.com');

    expect($stub['index'])->toContain("header('Location: https://staging.example.com')")
        ->and($stub['index'])->toContain('exit;')
        ->and($stub['htaccess'])->toContain('RewriteRule ^(.*)$ index.php [L,QSA]');
});


test('redirect stub uses custom files and escapes the url', function () {
    $root = sys_get_temp_dir() .'/deploy-stub-'. bin2hex(random_bytes(6));
    mkdir($root .'/.deploy/stub', 0700, true);

    file_put_contents($root .'/.deploy/stub/index.php', "<?php header('Location: %url%'); exit;\n");
    file_put_contents($root .'/.deploy/stub/.htaccess', "custom\n");

    $stub = (new RedirectStub($root))->render("https://example.com/path'quote");

    expect($stub['index'])->toContain("https://example.com/path\\'quote")
        ->and($stub['htaccess'])->toBe("custom\n");
});


test('redirect stub requires the url placeholder', function () {
    $root = sys_get_temp_dir() .'/deploy-stub-'. bin2hex(random_bytes(6));
    mkdir($root .'/.deploy/stub', 0700, true);
    file_put_contents($root .'/.deploy/stub/index.php', '<?php exit;');
    file_put_contents($root .'/.deploy/stub/.htaccess', '');

    expect(fn () => (new RedirectStub($root))->render('https://example.com'))
        ->toThrow(RuntimeException::class, 'must contain %url%');
});


test('artifact verifier hashes files', function () {
    $file = tempnam(sys_get_temp_dir(), 'artifact-');
    file_put_contents($file, 'artifact');

    $hash = (new ArtifactVerifier())->hash($file);

    expect($hash)->toBe('sha256:'. hash('sha256', 'artifact'));
});


test('artifact verifier checks the remote hash', function () {
    $expected = 'sha256:'. hash('sha256', 'artifact');

    $okShell = new FakeShell(fn (): \Tool\Deploy\ShellResult => new \Tool\Deploy\ShellResult(
        0,
        hash('sha256', 'artifact'),
        '',
    ));
    $badShell = new FakeShell(fn (): \Tool\Deploy\ShellResult => new \Tool\Deploy\ShellResult(
        0,
        hash('sha256', 'other'),
        '',
    ));
    $missingTool = new FakeShell(fn (): \Tool\Deploy\ShellResult => new \Tool\Deploy\ShellResult(
        0,
        '__NO_TOOL__',
        '',
    ));

    $verifier = new ArtifactVerifier();
    $target = \Tests\Feature\Deploy\DeployFixtures::target();

    expect($verifier->verifyRemote($target, $okShell, '/tmp/release.tar.gz', $expected))
        ->toBe(['available' => true, 'ok' => true])
        ->and($verifier->verifyRemote($target, $badShell, '/tmp/release.tar.gz', $expected))
        ->toBe(['available' => true, 'ok' => false])
        ->and($verifier->verifyRemote($target, $missingTool, '/tmp/release.tar.gz', $expected))
        ->toBe(['available' => false, 'ok' => false]);
});
