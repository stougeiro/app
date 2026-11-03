<?php declare(strict_types=1);

use App\Core\Security\Csrf;
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
