<?php declare(strict_types=1);

namespace App\HelloWorld\Controller;

use App\Contract\Http\ControllerAbstract;

use STDW\Contract\Http\ResponseInterface;


/**
 * Renders the module view over the response accumulated by the middlewares.
 */
final class HelloWorldController extends ControllerAbstract
{
    /** @var list<string> */
    protected array $allowed = ['get'];


    /**
     * @return ResponseInterface
     */
    public function get(): ResponseInterface
    {
        return $this->view('hello:helloworld');
    }
}
