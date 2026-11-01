<?php declare(strict_types=1);

namespace App\Contract\Http;

use Sourcerer\Contract\Http\ControllerAbstract as SourcererControllerAbstract;

use STDW\Contract\Http\ResponseInterface;
use STDW\Contract\View\ViewManagerInterface;
use STDW\Http\Response\HtmlResponse;


abstract class ControllerAbstract extends SourcererControllerAbstract
{
    /**
     * Renders a view through the view manager.
     *
     * @param string $template
     * @param array<string, mixed> $data
     * @return ResponseInterface
     */
    final protected function view(string $template, array $data = []): ResponseInterface
    {
        /** @var ViewManagerInterface $view */
        $view = container()->get(ViewManagerInterface::class);

        $response = new HtmlResponse();

        return $response
            ->from($this->response)
            ->withBody($view->render($template, $data));
    }
}
