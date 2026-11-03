<?php declare(strict_types=1);

use Deploy\PlanGate;
use Deploy\Target;



function deployPlanWorkspace(): string
{
    $root = sys_get_temp_dir() .'/deploy-plan-'. bin2hex(random_bytes(6));

    mkdir($root .'/application/Config', 0700, true);
    mkdir($root .'/application/storage/log', 0700, true);
    mkdir($root .'/public_html/assets', 0700, true);

    file_put_contents($root .'/application/Config/app.php', '<?php return [];');
    file_put_contents($root .'/application/env.json', '{"environment":"staging"}');
    file_put_contents($root .'/application/storage/log/app.log', 'log');
    file_put_contents($root .'/public_html/index.php', '<?php');
    file_put_contents($root .'/public_html/assets/app.css', 'body{}');
    file_put_contents($root .'/composer.json', '{}');
    file_put_contents($root .'/composer.lock', '{}');

    return $root;
}


function deployPlanTarget(bool $requiresPlan = true, string $name = 'staging'): Target
{
    return new Target(
        $name,
        'staging.example.com',
        'deploy',
        22,
        null,
        '/srv/app/public_html',
        '/srv/app',
        'https://staging.example.com',
        ['/uploads/***'],
        ['dir' => '755', 'file' => '644', 'storage' => '775'],
        true,
        3,
        $requiresPlan,
    );
}


test('source hash ignores env and storage', function () {
    $root = deployPlanWorkspace();
    $gate = new PlanGate($root);
    $before = $gate->sourceHash(deployPlanTarget());

    file_put_contents($root .'/application/env.json', '{"environment":"production"}');
    file_put_contents($root .'/application/storage/log/app.log', 'outro log');

    expect($gate->sourceHash(deployPlanTarget()))->toBe($before);
});


test('source hash changes when application or public_html changes', function () {
    $root = deployPlanWorkspace();
    $gate = new PlanGate($root);
    $before = $gate->sourceHash(deployPlanTarget());

    file_put_contents($root .'/application/Config/app.php', '<?php return [1];');

    expect($gate->sourceHash(deployPlanTarget()))->not->toBe($before);
});


test('source hash changes when a target override changes', function () {
    $root = deployPlanWorkspace();
    $gate = new PlanGate($root);
    $before = $gate->sourceHash(deployPlanTarget());

    mkdir($root .'/.deploy/override/staging/public_html', 0700, true);
    file_put_contents($root .'/.deploy/override/staging/public_html/.htaccess', 'Options -Indexes');

    expect($gate->sourceHash(deployPlanTarget()))->not->toBe($before);
});


test('source hash changes when the target config changes', function () {
    $root = deployPlanWorkspace();
    $gate = new PlanGate($root);
    $before = $gate->sourceHash(deployPlanTarget());

    $changed = new Target(
        'staging',
        'staging.example.com',
        'deploy',
        22,
        null,
        '/srv/app/public_html',
        '/srv/app',
        'https://staging.example.com',
        ['/uploads/***'],
        ['dir' => '755', 'file' => '644', 'storage' => '775'],
        true,
        3,
        false,
    );

    expect($gate->sourceHash($changed))->not->toBe($before);
});


test('writes, reads, validates and consumes the plan', function () {
    $root = deployPlanWorkspace();
    $gate = new PlanGate($root);
    $target = deployPlanTarget();

    $source = $gate->sourceHash($target);
    $gate->write($target, $source, false, null);

    $plan = $gate->read($target);

    expect($plan)->not->toBeNull()
        ->and($plan['target'] ?? null)->toBe('staging')
        ->and($plan['sourceHash'] ?? null)->toBe($source)
        ->and($plan['withEnv'] ?? null)->toBeFalse()
        ->and($plan['envHash'] ?? null)->toBeNull();

    $gate->assertPushAllowed($target, $source, false, null);
    $gate->consume($target);

    expect($gate->read($target))->toBeNull();
});


test('requires a plan exactly matching the push', function () {
    $root = deployPlanWorkspace();
    $gate = new PlanGate($root);
    $target = deployPlanTarget();

    $source = $gate->sourceHash($target);
    $gate->write($target, $source, true, 'abc');

    expect(fn () => $gate->assertPushAllowed($target, $source, false, null))
        ->toThrow(RuntimeException::class, 'use: deploy push');

    expect(fn () => $gate->assertPushAllowed($target, $source, true, 'outro'))
        ->toThrow(RuntimeException::class, 'env changed');
});


test('rejects push when files changed since the plan', function () {
    $root = deployPlanWorkspace();
    $gate = new PlanGate($root);
    $target = deployPlanTarget();

    $source = $gate->sourceHash($target);
    $gate->write($target, $source, false, null);

    file_put_contents($root .'/public_html/index.php', '<?php echo 1;');

    expect(fn () => $gate->assertPushAllowed($target, $gate->sourceHash($target), false, null))
        ->toThrow(RuntimeException::class, 'files changed');
});


test('rejects push without a plan when the target requires one', function () {
    $root = deployPlanWorkspace();
    $gate = new PlanGate($root);
    $target = deployPlanTarget();

    expect(fn () => $gate->assertPushAllowed($target, $gate->sourceHash($target), false, null))
        ->toThrow(RuntimeException::class, 'missing plan');
});


test('target without requiresPlan ignores the plan', function () {
    $root = deployPlanWorkspace();
    $gate = new PlanGate($root);

    $gate->assertPushAllowed(deployPlanTarget(false), 'qualquer', true, 'qualquer');

    expect($gate->read(deployPlanTarget(false)))->toBeNull();
});


test('source hash fails without the application directory', function () {
    $root = sys_get_temp_dir() .'/deploy-plan-missing-'. bin2hex(random_bytes(6));
    mkdir($root .'/public_html', 0700, true);
    file_put_contents($root .'/composer.json', '{}');
    file_put_contents($root .'/composer.lock', '{}');

    expect(fn () => (new PlanGate($root))->sourceHash(deployPlanTarget()))
        ->toThrow(RuntimeException::class, 'missing directory');
});


test('source hash fails without the public html directory', function () {
    $root = deployPlanWorkspace();
    unlink($root .'/public_html/index.php');
    unlink($root .'/public_html/assets/app.css');
    rmdir($root .'/public_html/assets');
    rmdir($root .'/public_html');

    expect(fn () => (new PlanGate($root))->sourceHash(deployPlanTarget()))
        ->toThrow(RuntimeException::class, 'missing directory');
});


test('read rejects invalid plan json', function () {
    $root = deployPlanWorkspace();
    mkdir($root .'/.deploy/plans', 0700, true);
    file_put_contents($root .'/.deploy/plans/staging.json', '{');

    expect(fn () => (new PlanGate($root))->read(deployPlanTarget()))
        ->toThrow(RuntimeException::class, 'invalid plan');
});


test('read rejects a plan that is not an object', function () {
    $root = deployPlanWorkspace();
    mkdir($root .'/.deploy/plans', 0700, true);
    file_put_contents($root .'/.deploy/plans/staging.json', '"text"');

    expect(fn () => (new PlanGate($root))->read(deployPlanTarget()))
        ->toThrow(RuntimeException::class, 'expected object');
});


test('read rejects a plan with missing fields', function () {
    $root = deployPlanWorkspace();
    mkdir($root .'/.deploy/plans', 0700, true);
    file_put_contents($root .'/.deploy/plans/staging.json', '{}');

    expect(fn () => (new PlanGate($root))->read(deployPlanTarget()))
        ->toThrow(RuntimeException::class, 'missing or incorrect fields');
});


test('rejects a plan that belongs to another target', function () {
    $root = deployPlanWorkspace();
    $gate = new PlanGate($root);
    mkdir($root .'/.deploy/plans', 0700, true);
    file_put_contents($root .'/.deploy/plans/production.json', json_encode([
        'target' => 'staging',
        'sourceHash' => 'hash',
        'withEnv' => false,
        'envHash' => null,
        'createdAt' => '2026-11-03T00:00:00+00:00',
    ], JSON_THROW_ON_ERROR));

    $production = deployPlanTarget(true, 'production');

    expect(fn () => $gate->assertPushAllowed($production, $gate->sourceHash($production), false, null))
        ->toThrow(RuntimeException::class, 'another target');
});


test('rejects push with env when the plan was created without it', function () {
    $root = deployPlanWorkspace();
    $gate = new PlanGate($root);
    $target = deployPlanTarget();
    $source = $gate->sourceHash($target);

    $gate->write($target, $source, false, null);

    expect(fn () => $gate->assertPushAllowed($target, $source, true, 'abc'))
        ->toThrow(RuntimeException::class, 'plan found without --with-env');
});



