<?php declare(strict_types=1);

use App\Core\Error\ViewErrorRenderer;
use Sourcerer\Contract\Error\HttpException;
use Sourcerer\Http\Kernel;
use Tests\Fixtures\FakeRequest;

final class PlainErrorFixture extends RuntimeException
{
}

beforeEach(function () {
    $this->kernel = Kernel::create();
});

test('error page renders through the app view layer', function () {
    $response = $this->kernel->handle(new FakeRequest('GET', 'http://localhost/missing'));
    $body = (string) $response->getBody();

    expect($response->getStatus())->toBe(404)
        ->and($body)->toContain('Not Found');
});

test('development error page renders the diagnostic layout', function () {
    $this->kernel->handle(new FakeRequest('GET', 'http://localhost/'));

    $renderer = container()->make(ViewErrorRenderer::class);
    $html = $renderer->render(
        new RuntimeException('Boom'),
        'ERROR_ID',
        500,
        true,
        false,
    );

    expect($html)->toContain('Application Error')
        ->and($html)->toContain('Development Mode')
        ->and($html)->toContain('Stack Trace')
        ->and($html)->toContain('Code Snippet')
        ->and($html)->toContain('/assets/error/development.css')
        ->and($html)->not->toContain('<style>')
        ->and($html)->not->toContain('Local Variables');
});

test('production error page has only status and message', function () {
    $this->kernel->handle(new FakeRequest('GET', 'http://localhost/'));

    $renderer = container()->make(ViewErrorRenderer::class);
    $html = $renderer->render(
        new HttpException(404, 'Not Found'),
        'ERROR_ID',
        404,
        false,
        false,
    );

    expect($html)->toContain('>404<')
        ->and($html)->toContain('Not Found')
        ->and($html)->toContain('/assets/error/page.css')
        ->and($html)->not->toContain('ERROR_ID');
});

test('production error page falls back for an unknown status', function () {
    $this->kernel->handle(new FakeRequest('GET', 'http://localhost/'));

    $renderer = container()->make(ViewErrorRenderer::class);
    $html = $renderer->render(
        new HttpException(418, 'Teapot'),
        'ERROR_ID',
        418,
        false,
        false,
    );

    expect($html)->toContain('Erro')
        ->and($html)->toContain('Teapot');
});

test('production error page uses the generic message for unknown server errors', function () {
    $this->kernel->handle(new FakeRequest('GET', 'http://localhost/'));

    $renderer = container()->make(ViewErrorRenderer::class);
    $html = $renderer->render(
        new HttpException(599, 'Server failure'),
        'ERROR_ID',
        599,
        false,
        false,
    );

    expect($html)->toContain('Erro')
        ->and($html)->toContain('Ocorreu um erro inesperado.');
});

test('production error page accepts a title and message override', function () {
    $this->kernel->handle(new FakeRequest('GET', 'http://localhost/'));

    $renderer = container()->make(ViewErrorRenderer::class);
    $html = $renderer->render(
        new HttpException(403, 'Forbidden'),
        'ERROR_ID',
        403,
        false,
        false,
        ['title' => 'Custom Title', 'message' => 'Custom message'],
    );

    expect($html)->toContain('Custom Title')
        ->and($html)->toContain('Custom message');
});

test('development error page handles a class without a namespace', function () {
    $this->kernel->handle(new FakeRequest('GET', 'http://localhost/'));

    $renderer = container()->make(ViewErrorRenderer::class);
    $html = $renderer->render(
        new PlainErrorFixture('Plain failure'),
        'ERROR_ID',
        500,
        true,
        false,
    );

    expect($html)->toContain('PlainErrorFixture')
        ->and($html)->toContain('Plain failure');
});

test('development error page snippets a file shorter than the pad', function () {
    $this->kernel->handle(new FakeRequest('GET', 'http://localhost/'));

    $file = tempnam(sys_get_temp_dir(), 'tiny_error_') .'.php';
    file_put_contents($file, "<?php\nthrow new RuntimeException('tiny failure');\n");

    try {
        try {
            require $file;
        } catch (RuntimeException $error) {
            $renderer = container()->make(ViewErrorRenderer::class);
            $html = $renderer->render($error, 'ERROR_ID', 500, true, false);
        }

        expect($html)->toContain('Code Snippet')
            ->and($html)->toContain('tiny failure');
    } finally {
        @unlink($file);
    }
});

test('error page keeps json negotiation', function () {
    $response = $this->kernel->handle(new FakeRequest(
        'GET',
        'http://localhost/missing',
        ['Accept' => 'application/json'],
    ));

    $decoded = json_decode((string) $response->getBody(), true);

    expect($response->getHeader('Content-Type'))->toContain('application/json')
        ->and($response->getStatus())->toBe(404)
        ->and($decoded['status'])->toBe('error')
        ->and($decoded['code'])->toBe(404);
});
