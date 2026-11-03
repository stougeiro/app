<?php declare(strict_types=1);

namespace Tests\Fixtures;

use RuntimeException;


final class PhpServer
{
    private int $port;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];


    private function __construct(
        private readonly string $docroot,
    ) {
        $this->port = self::freePort();
    }


    public static function start(string $docroot): self
    {
        $server = new self($docroot);
        $server->boot();

        return $server;
    }


    public function url(string $path = '/'): string
    {
        return 'http://127.0.0.1:'. $this->port . $path;
    }


    /**
     * @return array{status:int, headers:array<string, string>, body:string}
     */
    public function get(string $path = '/'): array
    {
        $context = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);

        $body = file_get_contents($this->url($path), false, $context);

        if ($body === false) {
            throw new RuntimeException('could not read response from '. $this->url($path));
        }

        /** @var list<string> $rawHeaders */
        $rawHeaders = $http_response_header ?? [];

        return self::parse($rawHeaders, $body);
    }


    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes = [];

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        $this->process = null;
    }


    private function boot(): void
    {
        $index = $this->docroot .'/index.php';

        if ( ! is_file($index)) {
            throw new RuntimeException('entrypoint not found: '. $index);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'. $this->port, '-t', $this->docroot, $index],
            $descriptors,
            $pipes,
            $this->docroot,
        );

        if ( ! is_resource($process)) {
            throw new RuntimeException('could not start the php server');
        }

        $this->process = $process;
        $this->pipes = $pipes;

        foreach ([1, 2] as $descriptor) {
            stream_set_blocking($this->pipes[$descriptor], false);
        }

        $this->waitUntilReady();
    }


    private function waitUntilReady(): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if ($this->isListening()) {
                return;
            }

            if ( ! $this->isRunning()) {
                throw new RuntimeException('php server stopped: '. $this->readErrors());
            }

            usleep(100_000);
        }

        throw new RuntimeException('php server did not become ready');
    }


    private function isListening(): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            $socket = stream_socket_client('tcp://127.0.0.1:'. $this->port, $errno, $error, 0.1);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }


    private function isRunning(): bool
    {
        if ( ! is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);

        return $status['running'];
    }


    private function readErrors(): string
    {
        $stream = $this->pipes[2] ?? null;

        if ( ! is_resource($stream)) {
            return '';
        }

        $errors = stream_get_contents($stream);

        return is_string($errors) ? trim($errors) : '';
    }


    private static function freePort(): int
    {
        set_error_handler(static fn (): bool => true);

        try {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            throw new RuntimeException('could not allocate a free port');
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ( ! is_string($name)) {
            throw new RuntimeException('could not resolve the allocated port');
        }

        $position = strrpos($name, ':');

        if ($position === false) {
            throw new RuntimeException('could not parse the allocated port');
        }

        return (int) substr($name, $position + 1);
    }


    /**
     * @param list<string> $rawHeaders
     * @return array{status:int, headers:array<string, string>, body:string}
     */
    private static function parse(array $rawHeaders, string $body): array
    {
        $status = 0;
        $headers = [];

        foreach ($rawHeaders as $line) {
            if (str_starts_with($line, 'HTTP/')) {
                $parts = explode(' ', $line, 3);
                $status = (int) ($parts[1] ?? 0);
                continue;
            }

            $position = strpos($line, ':');

            if ($position === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $position)));
            $headers[$name] = trim(substr($line, $position + 1));
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
