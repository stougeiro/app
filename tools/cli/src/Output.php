<?php declare(strict_types=1);

namespace Tool\Cli;

use JsonException;
use Tool\Contract\OutputInterface;


final class Output implements OutputInterface
{
    /** @var resource */
    protected $stdout;

    /** @var resource */
    protected $stderr;

    protected bool $quiet;

    protected bool $ansi;


    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(
        $stdout = null,
        $stderr = null,
        bool $quiet = false,
        bool $ansi = true,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->quiet = $quiet;
        $this->ansi = $ansi && $this->supportsAnsi($this->stdout);
    }


    public function line(string $text = ''): void
    {
        $this->write($text, '');
    }

    public function info(string $text): void
    {
        $this->write($text, '36');
    }

    public function success(string $text): void
    {
        $this->write($text, '32');
    }

    public function warning(string $text): void
    {
        $this->write($text, '33');
    }

    public function error(string $text): void
    {
        if ($this->quiet || ! is_resource($this->stderr)) {
            return;
        }

        fwrite($this->stderr, $this->color($text, '31') . PHP_EOL);
    }

    public function separator(): void
    {
        $this->line(str_repeat('-', 60));
    }

    /** @param array<string, mixed> $data */
    public function json(array $data): void
    {
        if ($this->quiet) {
            return;
        }

        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            $this->error('could not encode JSON: '. $exception->getMessage());

            return;
        }

        $this->line($encoded);
    }

    protected function write(string $text, string $color): void
    {
        if ($this->quiet || ! is_resource($this->stdout)) {
            return;
        }

        fwrite($this->stdout, $this->color($text, $color) . PHP_EOL);
    }

    protected function color(string $text, string $color): string
    {
        if ( ! $this->ansi || $color === '') {
            return $text;
        }

        return "\033[{$color}m{$text}\033[0m";
    }

    /** @param resource $stream */
    protected function supportsAnsi($stream): bool
    {
        return function_exists('stream_isatty') && @stream_isatty($stream);
    }
}
