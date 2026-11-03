<?php declare(strict_types=1);

use Tests\Fixtures\PhpServer;

function entrypointProjectRoot(): string
{
    return dirname(__DIR__, 2);
}

function entrypointTempRoot(?string $bootstrap = null): string
{
    $project = entrypointProjectRoot();
    $root = sys_get_temp_dir() . '/entrypoint_'. uniqid();
    $public = $root .'/public_html';

    mkdir($public, 0777, true);
    copy($project .'/public_html/index.php', $public .'/index.php');

    symlink($project .'/vendor', $root .'/vendor');

    if ($bootstrap === null) {
        symlink($project .'/application', $root .'/application');
    } else {
        mkdir($root .'/application/bootstrap', 0777, true);
        file_put_contents($root .'/application/bootstrap/app.php', $bootstrap);
    }

    return $root;
}

function entrypointRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }

    if ( ! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            unlink($item->getPathname());
            continue;
        }

        rmdir($item->getPathname());
    }

    rmdir($path);
}

function entrypointWithServer(string $root, Closure $test): void
{
    $server = PhpServer::start($root .'/public_html');

    try {
        $test($server);
    } finally {
        $server->stop();
        entrypointRemoveTree($root);
    }
}

test('maintenance mode blocks the application', function () {
    $root = entrypointTempRoot();
    file_put_contents($root .'/public_html/.maintenance', '');

    entrypointWithServer($root, function (PhpServer $server): void {
        $response = $server->get('/');

        expect($response['status'])->toBe(503)
            ->and($response['headers']['retry-after'] ?? null)->toBe('3600')
            ->and($response['headers']['cache-control'] ?? null)->toContain('no-store')
            ->and($response['headers']['content-type'] ?? null)->toContain('text/html')
            ->and($response['body'])->toContain('Maintenance')
            ->and($response['body'])->not->toContain('Hello World');
    });
});

test('maintenance mode falls back when the page file is missing', function () {
    $root = entrypointTempRoot();
    file_put_contents($root .'/public_html/.maintenance', '');

    entrypointWithServer($root, function (PhpServer $server): void {
        $response = $server->get('/');

        expect($response['status'])->toBe(503)
            ->and($response['body'])->toContain('class="message"')
            ->and($response['body'])->toContain('Maintenance')
            ->and($response['body'])->not->toContain('Hello World');
    });
});

test('maintenance mode serves the static page when present', function () {
    $root = entrypointTempRoot();
    file_put_contents($root .'/public_html/.maintenance', '');
    copy(entrypointProjectRoot() .'/public_html/maintenance.html', $root .'/public_html/maintenance.html');

    entrypointWithServer($root, function (PhpServer $server): void {
        $response = $server->get('/');

        expect($response['status'])->toBe(503)
            ->and($response['body'])->toContain('/assets/maintenance.css')
            ->and($response['body'])->not->toContain('Hello World');
    });
});

test('entrypoint boots the application outside maintenance mode', function () {
    $root = entrypointTempRoot();

    entrypointWithServer($root, function (PhpServer $server): void {
        $home = $server->get('/');
        $missing = $server->get('/missing');

        expect($home['status'])->toBe(200)
            ->and($home['body'])->toContain('Hello World')
            ->and($missing['status'])->toBe(404);
    });
});

test('entrypoint emits the fallback page when boot fails', function () {
    $bootstrap = <<<'PHP'
        <?php declare(strict_types=1);
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        throw new RuntimeException('boot failure');
        PHP;

    $root = entrypointTempRoot($bootstrap);

    entrypointWithServer($root, function (PhpServer $server): void {
        $response = $server->get('/');

        expect($response['status'])->toBe(500)
            ->and($response['headers']['x-error-id'] ?? null)->not->toBeEmpty()
            ->and($response['headers']['content-type'] ?? null)->toContain('text/html')
            ->and($response['headers']['cache-control'] ?? null)->toContain('no-store')
            ->and($response['headers']['x-content-type-options'] ?? null)->toBe('nosniff')
            ->and($response['body'])->toContain('Internal Server Error')
            ->and($response['body'])->not->toContain('boot failure')
            ->and($response['body'])->not->toContain('Stack trace');
    });
});
