<?php declare(strict_types=1);

use App\Core\Security\Csrf;
use Sourcerer\Http\Kernel;
use Tests\Fixtures\ArraySession;
use Tests\Fixtures\FakeRequest;

test('csrf token is stable inside the session', function () {
    $csrf = new Csrf(new ArraySession());

    $first = $csrf->token();
    $second = $csrf->token();

    expect($first)->toBe($second)
        ->and($first)->toHaveLength(64);
});

test('csrf token can be validated from the request header', function () {
    $csrf = new Csrf(new ArraySession());
    $token = $csrf->token();

    expect($csrf->validate(new FakeRequest('POST', 'http://localhost/', [
        'X-CSRF-Token' => $token,
    ])))->toBeTrue()
        ->and($csrf->validate(new FakeRequest('POST', 'http://localhost/', [
            'X-CSRF-Token' => 'invalid',
        ])))->toBeFalse();
});

test('csrf token rotation invalidates the previous token', function () {
    $csrf = new Csrf(new ArraySession());
    $previous = $csrf->token();
    $current = $csrf->rotate();

    expect($current)->not->toBe($previous)
        ->and($csrf->validate(new FakeRequest('POST', 'http://localhost/', [
            'X-CSRF-Token' => $previous,
        ])))->toBeFalse()
        ->and($csrf->validate(new FakeRequest('POST', 'http://localhost/', [
            'X-CSRF-Token' => $current,
        ])))->toBeTrue();
});

test('csrf factory builds a working instance from the container', function () {
    Kernel::create()->handle(new FakeRequest('GET', 'http://localhost/'));

    $csrf = Csrf::factory(container());
    $token = $csrf->token();

    expect($token)->toHaveLength(64)
        ->and($csrf->validate(new FakeRequest('POST', 'http://localhost/', [
            'X-CSRF-Token' => $token,
        ])))->toBeTrue();
});

test('csrf validation fails without a session token', function () {
    $csrf = new Csrf(new ArraySession());

    expect($csrf->validate(new FakeRequest('POST', 'http://localhost/', [
        'X-CSRF-Token' => 'anything',
    ])))->toBeFalse();
});

test('csrf accepts the token from the request input', function () {
    $csrf = new Csrf(new ArraySession());
    $token = $csrf->token();

    expect($csrf->validate(new FakeRequest('POST', 'http://localhost/', [], [
        '_token' => $token,
    ])))->toBeTrue();
});

test('csrf rejects non-string input tokens', function () {
    $csrf = new Csrf(new ArraySession());
    $csrf->token();

    expect($csrf->validate(new FakeRequest('POST', 'http://localhost/', [], [
        '_token' => ['invalid'],
    ])))->toBeFalse();
});

test('csrf header takes precedence over input', function () {
    $csrf = new Csrf(new ArraySession());
    $token = $csrf->token();

    expect($csrf->validate(new FakeRequest('POST', 'http://localhost/', [
        'X-CSRF-Token' => 'invalid',
    ], [
        '_token' => $token,
    ])))->toBeFalse();
});
