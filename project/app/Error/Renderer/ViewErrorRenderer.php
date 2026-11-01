<?php declare(strict_types=1);

namespace App\Error\Renderer;

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
        400 => ['title' => 'Requisição inválida', 'message' => 'A requisição não pôde ser entendida.'],
        403 => ['title' => 'Acesso negado', 'message' => 'Você não tem permissão para acessar este recurso.'],
        404 => ['title' => 'Não encontrado', 'message' => 'O recurso solicitado não existe.'],
        405 => ['title' => 'Método não permitido', 'message' => 'O método usado não é aceito por este recurso.'],
        429 => ['title' => 'Muitas requisições', 'message' => 'Aguarde alguns instantes e tente novamente.'],
        500 => ['title' => 'Erro na aplicação', 'message' => 'Ocorreu um erro inesperado.'],
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

        if ($status >= 500 && $development) {
            return $this->views->render('error:development', [
                'id' => $id,
                'type' => $error::class,
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString(),
                'snippet' => $this->snippet($error->getFile(), $error->getLine()),
            ]);
        }

        $message = $this->message($error, $status, $override);

        return $this->views->render('error:page', [
            'id' => $id,
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
