<?php declare(strict_types=1);

namespace Tests\Feature\Code;

use RuntimeException;
use Sourcerer\Tool\Manager\Output;
use Sourcerer\Tool\Code\ShellResult;
use Sourcerer\Tool\Contract\Operator;
use Sourcerer\Tool\Code\Target;


final class DeployFixtures
{
    /**
     * @param list<string> $nestedWebroots
     */
    public static function target(
        string $name = 'staging',
        ?string $identity = null,
        string $root = '/srv/app',
        string $webroot = '/srv/app/public_html',
        int $backupKeep = 3,
        array $nestedWebroots = [],
        ?string $rootBase = null,
        ?string $rootDir = null,
    ): Target {
        return new Target(
            $name,
            'staging.example.com',
            'deploy',
            22,
            $identity,
            $webroot,
            $root,
            $rootBase ?? $root,
            $rootDir,
            'https://staging.example.com',
            ['/.well-known/***'],
            $backupKeep,
            $nestedWebroots,
        );
    }



    public static function operator(): Operator
    {
        return new Operator('Test Operator', 'test@example.com');
    }



    public static function output(mixed $stream = null): Output
    {
        if ($stream === null) {
            $stream = fopen('php://memory', 'w+');
        }

        if ( ! is_resource($stream)) {
            throw new RuntimeException('could not open output stream');
        }

        return new Output($stream);
    }


    public static function workspace(): string
    {
        $root = sys_get_temp_dir() .'/deploy-'. bin2hex(random_bytes(6));

        foreach ([
            'application/Config',
            'public/assets',
            '.sourcerer/code/override/staging/application',
            '.sourcerer/code/override/staging/public',
        ] as $directory) {
            mkdir($root .'/'. $directory, 0700, true);
        }

        file_put_contents(
            $root .'/.sourcerer/operator.json',
            json_encode(['name' => 'Test Operator', 'email' => 'test@example.com'], JSON_THROW_ON_ERROR),
        );
        file_put_contents($root .'/composer.json', '{}');
        file_put_contents($root .'/composer.lock', '{}');
        file_put_contents($root .'/application/Config/app.php', '<?php return [];');
        file_put_contents($root .'/public/index.php', '<?php');
        file_put_contents($root .'/public/assets/app.css', 'body{}');
        file_put_contents($root .'/.sourcerer/code/override/staging/public/.htaccess', 'Options -Indexes');
        file_put_contents($root .'/.sourcerer/code/override/staging/application/env.json', '{"environment":"staging"}');

        return $root;
    }


    public static function shell(
        bool $failWebrootRsync = false,
        string $snapshotList = '',
        bool $hasRsync = true,
        string $rootStatus = '200',
        string $missingStatus = '404',
        string $rootBody = '',
        ?string $manifest = null,
        bool $failComposer = false,
        bool $composerNoVendor = false,
    ): FakeShell {
        return new FakeShell(function (array $command, ?string $stdin = null, ?string $cwd = null) use (
            $failWebrootRsync,
            $snapshotList,
            $hasRsync,
            $rootStatus,
            $missingStatus,
            $rootBody,
            $manifest,
            $failComposer,
            $composerNoVendor,
        ): ShellResult {
            $binary = $command[0];
            $remote = $command[count($command) - 1];

            if ($binary === 'tar' && in_array('-czf', $command, true)) {
                $target = $command[array_search('-czf', $command, true) + 1] ?? null;

                if (is_string($target)) {
                    @file_put_contents($target, '');
                }

                return new ShellResult(0, '', '');
            }

            if ($binary === 'ssh' && (str_contains($remote, 'hash_file') || str_contains($remote, 'sha256sum') || str_contains($remote, 'shasum') || str_contains($remote, 'openssl dgst'))) {
                return new ShellResult(0, hash('sha256', ''), '');
            }

            if ($binary === 'ssh') {
                if (str_contains($remote, 'command -v rsync')) {
                    return new ShellResult(0, $hasRsync ? 'yes' : 'no', '');
                }

                if (str_contains($remote, 'ls -1dt')) {
                    return new ShellResult(0, $snapshotList, '');
                }

                if (str_contains($remote, 'ls -1 ')) {
                    return new ShellResult(0, $snapshotList, '');
                }

                if (str_contains($remote, 'manifest.json')) {
                    if ($manifest !== null) {
                        return new ShellResult(0, $manifest, '');
                    }

                    return new ShellResult(0, json_encode([
                        'target' => 'staging',
                        'root' => '/srv/app',
                        'webroot' => '/srv/app/public_html',
                    ], JSON_THROW_ON_ERROR), '');
                }

                return new ShellResult(0, '', '');
            }

            if ($binary === 'composer') {
                if ($cwd === null) {
                    return new ShellResult(1, '', 'missing cwd');
                }

                if ($failComposer) {
                    return new ShellResult(1, '', 'composer boom');
                }

                if ($composerNoVendor) {
                    return new ShellResult(0, '', '');
                }

                mkdir($cwd .'/vendor/composer', 0700, true);
                file_put_contents($cwd .'/vendor/autoload.php', '<?php');

                return new ShellResult(0, '', '');
            }

            if ($binary === 'rsync') {
                $joined = implode(' ', $command);

                if ($failWebrootRsync
                    && str_contains($joined, '/public_html/')
                    && ! str_contains($joined, '/override/')) {
                    return new ShellResult(1, '', 'rsync boom');
                }

                return new ShellResult(0, '', '');
            }

            if ($binary === 'curl') {
                $url = $command[count($command) - 1];

                if (str_contains($url, '_sr-check')) {
                    return new ShellResult(0, json_encode([
                        'php' => '8.2.20',
                        'extensions' => ['pdo', 'pdo_sqlite'],
                    ], JSON_THROW_ON_ERROR), '');
                }
                $status = str_ends_with($url, '/missing') ? $missingStatus : $rootStatus;

                if (in_array('-o', $command, true)) {
                    return new ShellResult(0, $status, '');
                }

                return new ShellResult(0, $rootBody, '');
            }

            return new ShellResult(0, '', '');
        });
    }


    public static function calls(FakeShell $shell): string
    {
        return implode("\n", array_map(
            static fn (array $call): string => implode(' ', $call['command']),
            $shell->calls,
        ));
    }
}
