<?php declare(strict_types=1);

use App\Error\Renderer\ViewErrorRenderer;
use Sourcerer\Contract\Error\HttpException;
use Sourcerer\Http\Kernel;
use Tests\Fixtures\FakeRequest;

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
        ->and($html)->not->toContain('ERROR_ID');
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
