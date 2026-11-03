<?php declare(strict_types=1);

use Deploy\Config;



/**
 * @param array<string, mixed> $data
 */
function deployConfigFile(array $data): string
{
    $file = tempnam(sys_get_temp_dir(), 'deploy-config-');

    if ($file === false)
    {
        throw new RuntimeException('could not create temporary config');
    }

    file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));

    return $file;
}


test('loads defaults, explicit root and applies deep merge', function () {
    $config = Config::load(deployConfigFile([
        'defaults' => [
            'ssh' => [
                'port' => 2222,
                'identity' => '~/.ssh/id_ed25519',
            ],
            'preserve' => ['/uploads/***'],
            'permissions' => [
                'dir' => '750',
                'storage' => '770',
            ],
            'backup' => [
                'keep' => 5,
            ],
        ],
        'targets' => [
            'staging' => [
                'ssh' => [
                    'host' => 'staging.example.com',
                    'user' => 'deploy',
                ],
                'root' => '/srv/staging.example.com/app/',
                'webroot' => '/srv/staging.example.com/public_html/',
                'url' => 'https://staging.example.com',
            ],
        ],
    ]));

    $target = $config->target('staging');

    expect($target->name)->toBe('staging')
        ->and($target->sshHost)->toBe('staging.example.com')
        ->and($target->sshUser)->toBe('deploy')
        ->and($target->sshPort)->toBe(2222)
        ->and($target->sshIdentity)->toBe('~/.ssh/id_ed25519')
        ->and($target->webroot)->toBe('/srv/staging.example.com/public_html')
        ->and($target->root)->toBe('/srv/staging.example.com/app')
        ->and($target->url)->toBe('https://staging.example.com')
        ->and($target->preserve)->toBe(['/uploads/***'])
        ->and($target->permissions)->toBe(['dir' => '750', 'file' => '644', 'storage' => '770'])
        ->and($target->backupEnabled)->toBeTrue()
        ->and($target->backupKeep)->toBe(5)
        ->and($target->requiresPlan)->toBeFalse();
});


test('production can require a plan and custom backup', function () {
    $config = Config::load(deployConfigFile([
        'targets' => [
            'production' => [
                'ssh' => [
                    'host' => 'example.com',
                    'user' => 'deploy',
                ],
                'root' => '/srv/example.com/app',
                'webroot' => '/srv/example.com/public_html',
                'url' => 'https://example.com',
                'requiresPlan' => true,
                'backup' => [
                    'keep' => 10,
                ],
            ],
        ],
    ]));

    $target = $config->target('production');

    expect($target->requiresPlan)->toBeTrue()
        ->and($target->backupKeep)->toBe(10)
        ->and($target->backupEnabled)->toBeTrue();
});


test('rejects targets outside known environments', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => [
            'homolog' => [
                'ssh' => ['host' => 'h', 'user' => 'u'],
                'root' => '/srv/app',
                'webroot' => '/srv/app/public_html',
                'url' => 'https://app.example.com',
            ],
        ],
    ])))->toThrow(RuntimeException::class, 'invalid target');
});


test('rejects a relative webroot', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => [
            'staging' => [
                'ssh' => ['host' => 'h', 'user' => 'u'],
                'root' => '/srv/app',
                'webroot' => 'public_html',
                'url' => 'https://app.example.com',
            ],
        ],
    ])))->toThrow(RuntimeException::class, 'webroot');
});


test('rejects a relative root', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => [
            'staging' => [
                'ssh' => ['host' => 'h', 'user' => 'u'],
                'root' => 'app',
                'webroot' => '/srv/app/public_html',
                'url' => 'https://app.example.com',
            ],
        ],
    ])))->toThrow(RuntimeException::class, 'root');
});


test('rejects the same root and webroot', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => [
            'staging' => [
                'ssh' => ['host' => 'h', 'user' => 'u'],
                'root' => '/srv/app',
                'webroot' => '/srv/app',
                'url' => 'https://app.example.com',
            ],
        ],
    ])))->toThrow(RuntimeException::class, 'same');
});


test('rejects a root inside the webroot', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => [
            'staging' => [
                'ssh' => ['host' => 'h', 'user' => 'u'],
                'root' => '/srv/app/public_html/code',
                'webroot' => '/srv/app/public_html',
                'url' => 'https://app.example.com',
            ],
        ],
    ])))->toThrow(RuntimeException::class, 'inside');
});


test('rejects preserve without a leading slash', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => [
            'staging' => [
                'ssh' => ['host' => 'h', 'user' => 'u'],
                'root' => '/srv/app',
                'webroot' => '/srv/app/public_html',
                'url' => 'https://app.example.com',
                'preserve' => ['uploads/***'],
            ],
        ],
    ])))->toThrow(RuntimeException::class, 'invalid preserve');
});


test('rejects a non-octal permission', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => [
            'staging' => [
                'ssh' => ['host' => 'h', 'user' => 'u'],
                'root' => '/srv/app',
                'webroot' => '/srv/app/public_html',
                'url' => 'https://app.example.com',
                'permissions' => ['dir' => 'rwx'],
            ],
        ],
    ])))->toThrow(RuntimeException::class, 'octal');
});


test('resolves a relative ssh identity against the config root', function () {
    $file = deployConfigFile([
        'targets' => [
            'staging' => [
                'ssh' => [
                    'host' => 'h',
                    'user' => 'u',
                    'identity' => '.deploy/ssh/production_ed25519',
                ],
                'root' => '/srv/app',
                'webroot' => '/srv/app/public_html',
                'url' => 'https://app.example.com',
            ],
        ],
    ]);

    $target = Config::load($file)->target('staging');
    $root = realpath(dirname($file));

    expect($target->sshIdentity)->toBe($root .'/.deploy/ssh/production_ed25519');
});


test('keeps absolute and home-relative ssh identities unchanged', function () {
    $config = Config::load(deployConfigFile([
        'targets' => [
            'staging' => [
                'ssh' => [
                    'host' => 'h',
                    'user' => 'u',
                    'identity' => '/opt/keys/deploy_ed25519',
                ],
                'root' => '/srv/app',
                'webroot' => '/srv/app/public_html',
                'url' => 'https://app.example.com',
            ],
            'production' => [
                'ssh' => [
                    'host' => 'h',
                    'user' => 'u',
                    'identity' => '~/.ssh/deploy_ed25519',
                ],
                'root' => '/srv/production',
                'webroot' => '/srv/public_html',
                'url' => 'https://example.com',
            ],
        ],
    ]));

    expect($config->target('staging')->sshIdentity)->toBe('/opt/keys/deploy_ed25519')
        ->and($config->target('production')->sshIdentity)->toBe('~/.ssh/deploy_ed25519');
});


test('rejects a relative ssh identity escaping the project root', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => [
            'staging' => [
                'ssh' => [
                    'host' => 'h',
                    'user' => 'u',
                    'identity' => '../../outside_ed25519',
                ],
                'root' => '/srv/app',
                'webroot' => '/srv/app/public_html',
                'url' => 'https://app.example.com',
            ],
        ],
    ])))->toThrow(RuntimeException::class, 'escapes project root');
});


test('allows development and staging to share addresses', function () {
    $config = Config::load(deployConfigFile([
        'targets' => [
            'development' => [
                'ssh' => ['host' => 'shared.example.com', 'user' => 'deploy'],
                'root' => '/srv/app',
                'webroot' => '/srv/app/public_html',
                'url' => 'https://shared.example.com',
            ],
            'staging' => [
                'ssh' => ['host' => 'shared.example.com', 'user' => 'deploy'],
                'root' => '/srv/app',
                'webroot' => '/srv/app/public_html',
                'url' => 'https://shared.example.com',
            ],
        ],
    ]));

    expect($config->names())->toBe(['development', 'staging']);
});


test('allows the same address on different servers', function () {
    $config = Config::load(deployConfigFile([
        'targets' => [
            'staging' => [
                'ssh' => ['host' => 'staging.example.com', 'user' => 'deploy'],
                'root' => '/srv/app',
                'webroot' => '/srv/app/public_html',
                'url' => 'https://example.com',
            ],
            'production' => [
                'ssh' => ['host' => 'example.com', 'user' => 'deploy'],
                'root' => '/srv/app',
                'webroot' => '/srv/app/public_html',
                'url' => 'https://example.com',
            ],
        ],
    ]));

    expect($config->names())->toBe(['staging', 'production']);
});


test('rejects a non-production target reusing the production url on the same server', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => [
            'staging' => [
                'ssh' => ['host' => 'example.com', 'user' => 'deploy'],
                'root' => '/srv/staging',
                'webroot' => '/srv/public_html/staging',
                'url' => 'https://example.com/',
            ],
            'production' => [
                'ssh' => ['host' => 'example.com', 'user' => 'deploy'],
                'root' => '/srv/app',
                'webroot' => '/srv/public_html',
                'url' => 'https://example.com',
            ],
        ],
    ])))->toThrow(RuntimeException::class, 'must not reuse production url');
});


test('rejects a non-production target reusing the production root on the same server', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => [
            'staging' => [
                'ssh' => ['host' => 'example.com', 'user' => 'deploy'],
                'root' => '/srv/app',
                'webroot' => '/srv/public_html/staging',
                'url' => 'https://staging.example.com',
            ],
            'production' => [
                'ssh' => ['host' => 'example.com', 'user' => 'deploy'],
                'root' => '/srv/app',
                'webroot' => '/srv/public_html',
                'url' => 'https://example.com',
            ],
        ],
    ])))->toThrow(RuntimeException::class, 'must not reuse production root');
});


test('rejects a non-production target reusing the production webroot on the same server', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => [
            'staging' => [
                'ssh' => ['host' => 'example.com', 'user' => 'deploy'],
                'root' => '/srv/staging',
                'webroot' => '/srv/public_html',
                'url' => 'https://staging.example.com',
            ],
            'production' => [
                'ssh' => ['host' => 'example.com', 'user' => 'deploy'],
                'root' => '/srv/app',
                'webroot' => '/srv/public_html',
                'url' => 'https://example.com',
            ],
        ],
    ])))->toThrow(RuntimeException::class, 'must not reuse production webroot');
});
