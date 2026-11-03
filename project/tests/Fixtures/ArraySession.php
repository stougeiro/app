<?php declare(strict_types=1);

namespace Tests\Fixtures;

use STDW\Contract\Session\SessionInterface;


final class ArraySession implements SessionInterface
{
    public bool $started = false;

    /** @var array<string, mixed> */
    private array $data = [];


    public function start(): void
    {
        $this->started = true;
    }

    public function id(): string
    {
        return 'test-session';
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->started = false;
    }
}
