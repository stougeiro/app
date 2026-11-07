<?php declare(strict_types=1);

namespace Sourcerer\Tool\Manager;

use InvalidArgumentException;
use Sourcerer\Tool\Contract\InputInterface;
use Sourcerer\Tool\Contract\Option;


final class Input implements InputInterface
{
    /**
     * @param array<string, string|bool> $options
     * @param list<string> $arguments
     */
    public function __construct(
        private readonly array $options = [],
        private readonly array $arguments = [],
    ) {
    }


    /**
     * @param list<string> $tokens
     * @param list<Option> $definitions
     */
    public static function parse(array $tokens, array $definitions): self
    {
        $known = [];

        foreach ($definitions as $option) {
            $known[$option->name] = $option;
        }

        $options = [];
        $arguments = [];
        $literal = false;

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if ($literal) {
                $arguments[] = $token;
                continue;
            }

            if ($token === '--') {
                $literal = true;
                continue;
            }

            if ( ! str_starts_with($token, '--')) {
                $arguments[] = $token;
                continue;
            }

            $name = substr($token, 2);
            $value = true;

            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }

            $option = $known[$name] ?? null;

            if ($option === null) {
                throw new InvalidArgumentException("unknown option: --{$name}");
            }

            if ( ! $option->takesValue) {
                if ($value !== true) {
                    throw new InvalidArgumentException("--{$name} does not accept a value");
                }

                $options[$name] = true;
                continue;
            }

            if ($value === true) {
                $index++;
                $value = $tokens[$index] ?? '';

                if ($value === '') {
                    throw new InvalidArgumentException("--{$name} requires a value");
                }
            }

            $options[$name] = $value;
        }

        return new self($options, $arguments);
    }


    public function has(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function bool(string $name): bool
    {
        return ($this->options[$name] ?? false) === true;
    }

    public function string(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function int(string $name, int $default): int
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) && ctype_digit($value) ? (int) $value : $default;
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }
}
