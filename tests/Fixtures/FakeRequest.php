<?php declare(strict_types=1);

namespace Tests\Fixtures;

use STDW\Contract\Http\RequestInterface;
use STDW\Contract\Http\UploadedFileInterface;
use STDW\Contract\Http\UriInterface;
use STDW\Http\Uri;


final class FakeRequest implements RequestInterface
{
    private UriInterface $uri;

    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, mixed> */
    private array $attributes = [];


    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $method = 'GET',
        string $url = 'http://localhost/',
        array $headers = [],
    ) {
        $this->uri = Uri::fromUrl($url);

        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }


    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCookies(): array
    {
        return [];
    }

    /**
     * @return null|array<string, mixed>
     */
    public function getCookie(string $name): ?array
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return [];
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function getBody(): mixed
    {
        return null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    /**
     * @return array<string, array<int, UploadedFileInterface>|UploadedFileInterface>
     */
    public function getUploadedFiles(): array
    {
        return [];
    }

    /**
     * @return null|array<int, UploadedFileInterface>|UploadedFileInterface
     */
    public function getUploadedFile(string $name): null|array|UploadedFileInterface
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function withAttribute(string $name, mixed $value): RequestInterface
    {
        $this->attributes[$name] = $value;

        return $this;
    }

    public function withoutAttribute(string $name): RequestInterface
    {
        unset($this->attributes[$name]);

        return $this;
    }
}
