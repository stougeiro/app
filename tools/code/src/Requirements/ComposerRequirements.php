<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Requirements;

use RuntimeException;


final class ComposerRequirements
{
    /**
     * @param list<string> $extensions
     */
    public function __construct(
        public readonly ?string $php,
        public readonly array $extensions,
    ) {
    }


    public static function fromFile(string $file): self
    {
        if ( ! is_file($file)) {
            throw new RuntimeException("composer.json not found: {$file}");
        }

        $raw = file_get_contents($file);

        if ($raw === false) {
            throw new RuntimeException("could not read composer.json: {$file}");
        }

        $data = json_decode($raw, true);

        if ( ! is_array($data)) {
            throw new RuntimeException('invalid composer.json');
        }

        $require = $data['require'] ?? [];

        if ( ! is_array($require)) {
            throw new RuntimeException("composer.json 'require' must be an object");
        }

        $php = is_string($require['php'] ?? null) ? $require['php'] : null;
        $extensions = [];

        foreach ($require as $name => $version) {
            if (is_string($name) && str_starts_with($name, 'ext-')) {
                $extensions[] = substr($name, 4);
            }
        }

        sort($extensions, SORT_STRING);

        return new self($php, $extensions);
    }


    /** @param array{php: string, extensions: list<string>} $probe */
    public function assert(array $probe): void
    {
        if ($this->php !== null && ! $this->satisfies($probe['php'], $this->php)) {
            throw new RuntimeException(
                "requirements: PHP {$this->php} required, remote has {$probe['php']}",
            );
        }

        $loaded = array_map('strtolower', $probe['extensions']);

        foreach ($this->extensions as $extension) {
            if ( ! in_array(strtolower($extension), $loaded, true)) {
                throw new RuntimeException("requirements: missing PHP extension {$extension}");
            }
        }
    }


    private function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);

        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        if (str_contains($constraint, '||')) {
            throw new RuntimeException('requirements: unsupported PHP constraint (||)');
        }

        $version = preg_replace('/[^0-9.].*/', '', $version) ?: '0.0.0';

        foreach (preg_split('/\s+/', $constraint) ?: [] as $part) {
            if ( ! $this->satisfyPart($version, $part)) {
                return false;
            }
        }

        return true;
    }


    private function satisfyPart(string $version, string $constraint): bool
    {
        if (str_starts_with($constraint, '>=')) {
            return version_compare($version, substr($constraint, 2), '>=');
        }

        if (str_starts_with($constraint, '>')) {
            return version_compare($version, substr($constraint, 1), '>');
        }

        if (str_starts_with($constraint, '<=')) {
            return version_compare($version, substr($constraint, 2), '<=');
        }

        if (str_starts_with($constraint, '<')) {
            return version_compare($version, substr($constraint, 1), '<');
        }

        if (str_starts_with($constraint, '^') || str_starts_with($constraint, '~')) {
            $minimum = substr($constraint, 1);
            $segments = explode('.', $minimum);
            $major = (int) $segments[0];
            $upper = ($major + 1) .'.0';

            return version_compare($version, $minimum, '>=')
                && version_compare($version, $upper, '<');
        }

        if (str_ends_with($constraint, '.*')) {
            $prefix = substr($constraint, 0, -2);

            return str_starts_with($version, $prefix .'.') || $version === $prefix;
        }

        $operator = str_starts_with($constraint, '=') ? '=' : '>=';
        $value = ltrim($constraint, '=');

        return version_compare($version, $value, $operator);
    }
}
