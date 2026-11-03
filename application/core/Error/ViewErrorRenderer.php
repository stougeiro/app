<?php declare(strict_types=1);

namespace App\Core\Error;

use Sourcerer\Contract\Error\ErrorRendererInterface;
use Sourcerer\Error\ErrorRenderer;

use STDW\Contract\Container\ContainerInterface;
use STDW\Contract\Container\ServiceFactoryInterface;
use STDW\Contract\View\ViewManagerInterface;

use Throwable;

use function Sourcerer\config;


final class ViewErrorRenderer implements ErrorRendererInterface, ServiceFactoryInterface
{
    private const MESSAGES = [
        400 => ['title' => 'Bad Request', 'message' => 'The request could not be understood.'],
        403 => ['title' => 'Forbidden', 'message' => 'You do not have permission to access this resource.'],
        404 => ['title' => 'Not Found', 'message' => 'The requested resource does not exist.'],
        405 => ['title' => 'Method Not Allowed', 'message' => 'The method used is not accepted by this resource.'],
        429 => ['title' => 'Too Many Requests', 'message' => 'Wait a moment and try again.'],
        500 => ['title' => 'Internal Server Error', 'message' => 'An unexpected error occurred.'],
    ];


    public function __construct(
        private readonly ViewManagerInterface $views,
    ) { }


    public static function factory(ContainerInterface $container): static
    {
        /** @var ViewManagerInterface $views */
        $views = $container->get(ViewManagerInterface::class);

        return new self($views);
    }


    /**
     * @param array<string, mixed> $override
     */
    public function render(
        Throwable $error,
        string $id,
        int $status,
        bool $development,
        bool $json,
        array $override = [],
    ): string {
        if ($json) {
            return (new ErrorRenderer($this->snippetPad()))->render(
                $error,
                $id,
                $status,
                $development,
                true,
                $override,
            );
        }

        if ($development) {
            return $this->views->render('error:development', [
                'id' => $id,
                'type' => $error::class,
                'short_type' => $this->shortType($error::class),
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $this->trace($error),
                'snippet' => $this->snippet($error->getFile(), $error->getLine()),
            ]);
        }

        $message = $this->message($error, $status, $override);

        return $this->views->render('error:page', [
            'status' => $status,
            'title' => $message['title'],
            'message' => $message['message'],
        ]);
    }


    private function snippetPad(): int
    {
        $pad = config('error.snippet.pad', 10);

        if ( ! is_int($pad)) {
            $pad = is_numeric($pad) ? (int) $pad : 10;
        }

        return max(5, min(30, $pad));
    }

    private function shortType(string $type): string
    {
        $position = strrpos($type, '\\');

        return $position === false ? $type : substr($type, $position + 1);
    }

    /**
     * @return array<int, array{file: string, line: ?int, call: string}>
     */
    private function trace(Throwable $error): array
    {
        $frames = [];

        foreach ($error->getTrace() as $frame) {
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $function = $frame['function'];
            $file = $frame['file'] ?? '';
            $line = $frame['line'] ?? null;

            $frames[] = [
                'file' => $file,
                'line' => $line,
                'call' => $class . $type . $function .'()',
            ];
        }

        return $frames;
    }

    /**
     * @param array<string, mixed> $override
     * @return array{title: string, message: string}
     */
    private function message(Throwable $error, int $status, array $override): array
    {
        $default = self::MESSAGES[$status] ?? ['title' => 'Erro', 'message' => 'Ocorreu um erro inesperado.'];

        $title = $override['title'] ?? null;
        $message = $override['message'] ?? null;

        return [
            'title' => is_string($title) && $title !== '' ? $title : $default['title'],
            'message' => is_string($message)
                ? $message
                : ($status < 500 ? $error->getMessage() : $default['message']),
        ];
    }

    /**
     * @return array<int, array{num: int, code: string, hit: bool}>
     */
    private function snippet(string $file, int $line): array
    {
        if ($line < 1 || ! is_readable($file)) {
            return [];
        }

        $lines = file($file);

        if ($lines === false) {
            return [];
        }

        $pad = $this->snippetPad();
        $result = [];

        for ($number = max($line - $pad, 1); $number <= $line + $pad; $number++) {
            $code = $lines[$number - 1] ?? null;

            if ($code === null) {
                break;
            }

            $result[] = [
                'num' => $number,
                'code' => rtrim($code),
                'hit' => $number === $line,
            ];
        }

        return $result;
    }
}
