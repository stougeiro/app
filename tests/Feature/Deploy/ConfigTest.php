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


/**
 * @return array{base:string, dir?:string}
 */
function deployRoot(string $base, ?string $dir = null): array
{
    $root = ['base' => $base];

    if ($dir !== null)
    {
        $root['dir'] = $dir;
    }

    return $root;
}


/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function deployConfigTarget(array $overrides = []): array
{
    return array_replace([
        'ssh' => ['host' => 'app.example.com', 'user' => 'deploy'],
        'root' => deployRoot('/srv/app'),
        'webroot' => '/srv/app/public_html',
        'url' => 'https://app.example.com',
    ], $overrides);
}


/**
 * @param array<string, mixed> $target
 */
function deployConfigWithTarget(array $target): string
{
    return deployConfigFile(['targets' => ['staging' => $target]]);
}


test('loads defaults, explicit root and applies deep merge', function () {
    $config = Config::load(deployConfigFile([
        'defaults' => [
            'ssh' => [
                'port' => 2222,
                'identity' => '~/.ssh/id_ed25519',
            ],
            'preserve' => ['/.well-known/***'],
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
                'root' => deployRoot('/srv/staging.example.com/app/'),
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
        ->and($target->preserve)->toBe(['/.well-known/***'])
        ->and($target->backupKeep)->toBe(5)
        ->and($target->nestedWebroots)->toBe([]);
});


test('builds the root from base and dir', function () {
    $config = Config::load(deployConfigWithTarget(deployConfigTarget([
        'root' => deployRoot('/srv/domain', 'development'),
    ])));

    expect($config->target('staging')->root)->toBe('/srv/domain/development');
});


test('merges a target root dir with the default root base', function () {
    $config = Config::load(deployConfigFile([
        'defaults' => [
            'root' => deployRoot('/srv/domain'),
        ],
        'targets' => [
            'development' => deployConfigTarget([
                'root' => ['dir' => 'development'],
            ]),
        ],
    ]));

    expect($config->target('development')->root)->toBe('/srv/domain/development');
});


test('rejects root as a string', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'root' => '/srv/app',
    ]))))->toThrow(RuntimeException::class, "requires a 'root' object");
});


test('rejects root without a base', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'root' => [],
    ]))))->toThrow(RuntimeException::class, "'base' must be a non-empty string");
});


test('rejects invalid root dir', function () {
    foreach (['.', '..', 'dev/app', 'dev\\app', '~dev'] as $dir)
    {
        expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
            'root' => deployRoot('/srv/domain', $dir),
        ]))))->toThrow(RuntimeException::class, "invalid 'root.dir'");
    }
});


test('applies per-target backup keep', function () {
    $config = Config::load(deployConfigFile([
        'defaults' => ['backup' => ['keep' => 3]],
        'targets' => [
            'production' => deployConfigTarget([
                'backup' => ['keep' => 10],
            ]),
        ],
    ]));

    expect($config->target('production')->backupKeep)->toBe(10);
});


test('defaults backup keep to three', function () {
    $config = Config::load(deployConfigWithTarget(deployConfigTarget()));

    expect($config->target('staging')->backupKeep)->toBe(3);
});


test('rejects targets outside known environments', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => [
            'homolog' => deployConfigTarget(),
        ],
    ])))->toThrow(RuntimeException::class, 'invalid target');
});


test('rejects a relative webroot', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'webroot' => 'public_html',
    ]))))->toThrow(RuntimeException::class, 'webroot');
});


test('rejects a relative root', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'root' => deployRoot('app'),
    ]))))->toThrow(RuntimeException::class, 'root');
});


test('rejects path traversal in root or webroot', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'root' => deployRoot('/srv/app/../other'),
    ]))))->toThrow(RuntimeException::class, 'traversal');

    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'webroot' => '/srv/app/./public_html',
    ]))))->toThrow(RuntimeException::class, 'traversal');
});


test('rejects backslashes and tildes in paths', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'root' => deployRoot('/srv/app\\code'),
    ]))))->toThrow(RuntimeException::class, 'invalid');

    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'webroot' => '/srv/~/public_html',
    ]))))->toThrow(RuntimeException::class, 'invalid');
});


test('rejects the same root and webroot', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'root' => deployRoot('/srv/app'),
        'webroot' => '/srv/app',
    ]))))->toThrow(RuntimeException::class, 'same');
});


test('rejects a root inside the webroot', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'root' => deployRoot('/srv/app/public_html/code'),
        'webroot' => '/srv/app/public_html',
    ]))))->toThrow(RuntimeException::class, 'inside');
});


test('allows a webroot inside the root', function () {
    $config = Config::load(deployConfigWithTarget(deployConfigTarget([
        'root' => deployRoot('/srv/app'),
        'webroot' => '/srv/app/public_html',
    ])));

    expect($config->target('staging')->webroot)->toBe('/srv/app/public_html');
});


test('rejects preserve without a leading slash', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'preserve' => ['well-known/***'],
    ]))))->toThrow(RuntimeException::class, 'invalid preserve');
});


test('rejects a preserve value that is not a list', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'preserve' => 'invalid',
    ]))))->toThrow(RuntimeException::class, 'invalid list');
});


test('rejects a preserve item that is not a string', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'preserve' => [1],
    ]))))->toThrow(RuntimeException::class, 'list item must be a string');
});


test('resolves a relative ssh identity against the config root', function () {
    $file = deployConfigWithTarget(deployConfigTarget([
        'ssh' => ['host' => 'app.example.com', 'user' => 'deploy', 'identity' => '.deploy/ssh/key'],
    ]));

    expect(Config::load($file)->target('staging')->sshIdentity)
        ->toBe(dirname($file) .'/.deploy/ssh/key');
});


test('keeps absolute and home-relative ssh identities unchanged', function () {
    expect(Config::load(deployConfigWithTarget(deployConfigTarget([
        'ssh' => ['host' => 'app.example.com', 'user' => 'deploy', 'identity' => '/home/deploy/.ssh/key'],
    ])))->target('staging')->sshIdentity)->toBe('/home/deploy/.ssh/key');

    expect(Config::load(deployConfigWithTarget(deployConfigTarget([
        'ssh' => ['host' => 'app.example.com', 'user' => 'deploy', 'identity' => '~/.ssh/key'],
    ])))->target('staging')->sshIdentity)->toBe('~/.ssh/key');
});


test('rejects a relative ssh identity escaping the project root', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'ssh' => ['host' => 'app.example.com', 'user' => 'deploy', 'identity' => '../key'],
    ]))))->toThrow(RuntimeException::class, 'escapes project root');
});


test('allows development and staging to share non-address settings', function () {
    $config = Config::load(deployConfigFile([
        'targets' => [
            'development' => deployConfigTarget([
                'root' => deployRoot('/srv/development'),
                'webroot' => '/srv/public_html/development',
                'url' => 'https://dev.example.com',
            ]),
            'staging' => deployConfigTarget([
                'root' => deployRoot('/srv/staging'),
                'webroot' => '/srv/public_html/staging',
                'url' => 'https://staging.example.com',
            ]),
        ],
    ]));

    expect($config->names())->toBe(['development', 'staging']);
});


test('rejects targets sharing root, webroot or url on the same server', function () {
    foreach ([
        ['root' => deployRoot('/srv/shared'), 'webroot' => '/srv/staging/public_html', 'url' => 'https://staging.example.com'],
        ['root' => deployRoot('/srv/staging'), 'webroot' => '/srv/shared', 'url' => 'https://staging.example.com'],
        ['root' => deployRoot('/srv/staging'), 'webroot' => '/srv/staging/public_html', 'url' => 'https://app.example.com'],
    ] as $overrides)
    {
        expect(fn () => Config::load(deployConfigFile([
            'targets' => [
                'development' => deployConfigTarget($overrides),
                'staging' => deployConfigTarget([
                    'root' => deployRoot('/srv/staging'),
                    'webroot' => '/srv/staging/public_html',
                    'url' => 'https://staging.example.com',
                ]),
            ],
        ])))->toThrow(RuntimeException::class, 'must not share');
    }
});


test('rejects targets sharing a final root built from base and dir', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => [
            'development' => deployConfigTarget([
                'root' => deployRoot('/srv/domain', 'app'),
                'webroot' => '/srv/public_html/development',
                'url' => 'https://dev.example.com',
            ]),
            'staging' => deployConfigTarget([
                'root' => deployRoot('/srv/domain', 'app'),
                'webroot' => '/srv/public_html/staging',
                'url' => 'https://staging.example.com',
            ]),
        ],
    ])))->toThrow(RuntimeException::class, 'must not share root');
});


test('allows the same root base with different directories', function () {
    $config = Config::load(deployConfigFile([
        'targets' => [
            'development' => deployConfigTarget([
                'root' => deployRoot('/srv/domain', 'development'),
                'webroot' => '/srv/public_html/development',
                'url' => 'https://dev.example.com',
            ]),
            'staging' => deployConfigTarget([
                'root' => deployRoot('/srv/domain', 'staging'),
                'webroot' => '/srv/public_html/staging',
                'url' => 'https://staging.example.com',
            ]),
        ],
    ]));

    expect($config->target('development')->root)->toBe('/srv/domain/development')
        ->and($config->target('staging')->root)->toBe('/srv/domain/staging');
});


test('allows the same addresses on different servers', function () {
    $config = Config::load(deployConfigFile([
        'targets' => [
            'development' => deployConfigTarget([
                'ssh' => ['host' => 'dev.example.com', 'user' => 'deploy'],
            ]),
            'staging' => deployConfigTarget([
                'ssh' => ['host' => 'staging.example.com', 'user' => 'deploy'],
            ]),
        ],
    ]));

    expect($config->names())->toBe(['development', 'staging']);
});


test('detects direct child webroots for isolation', function () {
    $config = Config::load(deployConfigFile([
        'targets' => [
            'production' => deployConfigTarget([
                'root' => deployRoot('/srv/domain'),
                'webroot' => '/srv/domain/public_html',
                'url' => 'https://example.com',
            ]),
            'development' => deployConfigTarget([
                'root' => deployRoot('/srv/domain/dev'),
                'webroot' => '/srv/domain/public_html/dev',
                'url' => 'https://dev.example.com',
            ]),
            'staging' => deployConfigTarget([
                'root' => deployRoot('/srv/domain/staging'),
                'webroot' => '/srv/domain/public_html/staging',
                'url' => 'https://staging.example.com',
            ]),
        ],
    ]));

    expect($config->target('production')->nestedWebroots)->toBe(['dev', 'staging'])
        ->and($config->target('development')->nestedWebroots)->toBe([])
        ->and($config->target('staging')->nestedWebroots)->toBe([]);
});


test('rejects webroot nesting deeper than a direct child', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => [
            'production' => deployConfigTarget([
                'root' => deployRoot('/srv/domain'),
                'webroot' => '/srv/domain/public_html',
                'url' => 'https://example.com',
            ]),
            'development' => deployConfigTarget([
                'root' => deployRoot('/srv/domain/dev'),
                'webroot' => '/srv/domain/public_html/sub/dev',
                'url' => 'https://dev.example.com',
            ]),
        ],
    ])))->toThrow(RuntimeException::class, 'direct child');
});


test('rejects invalid config json', function () {
    $file = tempnam(sys_get_temp_dir(), 'deploy-config-');
    file_put_contents($file, '{');

    expect(fn () => Config::load($file))
        ->toThrow(RuntimeException::class, 'invalid config');
});


test('rejects a config that is not an object', function () {
    $file = tempnam(sys_get_temp_dir(), 'deploy-config-');
    file_put_contents($file, '"text"');

    expect(fn () => Config::load($file))
        ->toThrow(RuntimeException::class, 'must be a JSON object');
});


test('rejects a config without targets', function () {
    expect(fn () => Config::load(deployConfigFile([])))
        ->toThrow(RuntimeException::class, "'targets' must be a non-empty object");
});


test('rejects non-object defaults', function () {
    expect(fn () => Config::load(deployConfigFile([
        'defaults' => 'invalid',
        'targets' => ['staging' => deployConfigTarget()],
    ])))->toThrow(RuntimeException::class, "'defaults' must be an object");
});


test('rejects a target that is not an object', function () {
    expect(fn () => Config::load(deployConfigFile([
        'targets' => ['staging' => 'invalid'],
    ])))->toThrow(RuntimeException::class, 'must be an object');
});


test('rejects a target without ssh', function () {
    $target = deployConfigTarget();
    unset($target['ssh']);

    expect(fn () => Config::load(deployConfigWithTarget($target)))
        ->toThrow(RuntimeException::class, "'ssh' must be an object");
});


test('rejects invalid ssh fields', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'ssh' => ['user' => 'deploy'],
    ]))))->toThrow(RuntimeException::class, "'host' must be a non-empty string");

    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'ssh' => ['host' => 'app.example.com'],
    ]))))->toThrow(RuntimeException::class, "'user' must be a non-empty string");

    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'ssh' => ['host' => 'app.example.com', 'user' => 'deploy', 'port' => '22'],
    ]))))->toThrow(RuntimeException::class, "'port' must be an integer");

    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'ssh' => ['host' => 'app.example.com', 'user' => 'deploy', 'port' => 0],
    ]))))->toThrow(RuntimeException::class, 'invalid SSH port');

    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'ssh' => ['host' => 'app.example.com', 'user' => 'deploy', 'identity' => ''],
    ]))))->toThrow(RuntimeException::class, "'identity' must be a non-empty string");
});


test('rejects invalid urls', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'url' => 'not-a-url',
    ]))))->toThrow(RuntimeException::class, "invalid 'url'");

    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'url' => 'ftp://app.example.com',
    ]))))->toThrow(RuntimeException::class, "requires an http or https 'url'");
});


test('rejects invalid backup settings', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'backup' => 'invalid',
    ]))))->toThrow(RuntimeException::class, "'backup' must be an object");

    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'backup' => ['keep' => 0],
    ]))))->toThrow(RuntimeException::class, "'backup.keep' must be an integer >= 1");

    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'backup' => ['keep' => '3'],
    ]))))->toThrow(RuntimeException::class, "'backup.keep' must be an integer >= 1");
});


test('rejects root-level paths', function () {
    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'root' => deployRoot('/'),
    ]))))->toThrow(RuntimeException::class, "root-level 'root.base'");

    expect(fn () => Config::load(deployConfigWithTarget(deployConfigTarget([
        'webroot' => '/',
    ]))))->toThrow(RuntimeException::class, "root-level 'webroot'");
});
