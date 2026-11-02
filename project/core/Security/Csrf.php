<?php declare(strict_types=1);

namespace App\Core\Security;

use STDW\Contract\Container\ContainerInterface;
use STDW\Contract\Container\ServiceFactoryInterface;
use STDW\Contract\Http\RequestInterface;
use STDW\Contract\Session\SessionInterface;

use function Sourcerer\config;


final class Csrf implements ServiceFactoryInterface
{
    private const SESSION_KEY = '__csrf_token';


    public function __construct(
        private readonly SessionInterface $session,
        private readonly string $field = '_token',
        private readonly string $header = 'X-CSRF-Token',
    ) { }


    public static function factory(ContainerInterface $container): static
    {
        $config = config('security.csrf', []);
        $field = is_array($config) && is_string($config['field'] ?? null) ? $config['field'] : '_token';
        $header = is_array($config) && is_string($config['header'] ?? null) ? $config['header'] : 'X-CSRF-Token';

        /** @var SessionInterface $session */
        $session = $container->get(SessionInterface::class);

        return new self($session, $field, $header);
    }


    public function token(): string
    {
        $this->session->start();

        $token = $this->session->get(self::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            $token = $this->generate();
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function rotate(): string
    {
        $this->session->start();

        $token = $this->generate();
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function validate(RequestInterface $request): bool
    {
        $this->session->start();

        $expected = $this->session->get(self::SESSION_KEY);

        if (! is_string($expected) || $expected === '') {
            return false;
        }

        $token = $request->getHeader($this->header);

        if ($token === null || $token === '') {
            $input = $request->input($this->field);
            $token = is_string($input) ? $input : '';
        }

        return $token !== '' && hash_equals($expected, $token);
    }


    private function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
