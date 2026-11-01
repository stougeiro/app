<?php declare(strict_types=1);

use Sourcerer\Http\Kernel;
use STDW\Contract\Cache\CacheInterface;
use STDW\Contract\Event\EventManagerInterface;
use Sourcerer\Contract\Log\LogInterface;
use STDW\Contract\Pdo\ConnectionInterface;
use STDW\Contract\Pdo\DriverInterface;
use STDW\Contract\Session\SessionInterface;
use STDW\Contract\View\ViewManagerInterface;
use Tests\Fixtures\FakeRequest;

beforeEach(function () {
    $response = Kernel::create()->handle(new FakeRequest('GET', 'http://localhost/'));

    expect($response->getStatus())->toBe(200);
});

test('dependency services are available in the container', function () {
    expect(container()->get(CacheInterface::class))->toBeInstanceOf(CacheInterface::class)
        ->and(container()->get(ViewManagerInterface::class))->toBeInstanceOf(ViewManagerInterface::class)
        ->and(container()->get(SessionInterface::class))->toBeInstanceOf(SessionInterface::class)
        ->and(container()->get(EventManagerInterface::class))->toBeInstanceOf(EventManagerInterface::class)
        ->and(container()->get(EventManagerInterface::class))->toBe(events())
        ->and(container()->get(LogInterface::class))->toBeInstanceOf(LogInterface::class)
        ->and(container()->get(ConnectionInterface::class))->toBeInstanceOf(ConnectionInterface::class)
        ->and(container()->get(DriverInterface::class))->toBeInstanceOf(DriverInterface::class);
});
