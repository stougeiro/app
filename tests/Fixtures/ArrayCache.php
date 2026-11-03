<?php declare(strict_types=1);

namespace Tests\Fixtures;

use STDW\Contract\Cache\CacheInterface;


final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $items = [];


    public function __construct(
        private readonly bool $asObject = false,
    ) {
    }


    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items[$key] ?? $default;

        return $this->asObject && is_array($value) ? (object) $value : $value;
    }

    public function set(string $key, mixed $value, int $ttl = 300): bool
    {
        $this->items[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }
}
