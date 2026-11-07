<?php declare(strict_types=1);

namespace Tool\Deploy;

use JsonException;
use RuntimeException;
use Tool\Deploy\Operator\Operator;


class Config
{
    /** @var list<string> */
    public const TARGETS = ['development', 'staging', 'production', 'demonstration'];

    /** @var list<string> */
    public const PROMOTION = ['development', 'staging', 'production'];

    /** @var array<string, Target> */
    private array $targets = [];

    private ?Operator $operator = null;


    private function __construct()
    {
    }


    public static function load(string $file): self
    {
        if ( ! is_file($file) || ! is_readable($file))
        {
            throw new RuntimeException("config not found or unreadable: {$file}");
        }

        $raw = file_get_contents($file);

        if ($raw === false)
        {
            throw new RuntimeException("config could not be read: {$file}");
        }

        try
        {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        catch (JsonException $error)
        {
            throw new RuntimeException('invalid config: '. $error->getMessage(), 0, $error);
        }

        if ( ! is_array($data))
        {
            throw new RuntimeException('config must be a JSON object');
        }

        $targets = $data['targets'] ?? null;

        if ( ! is_array($targets) || $targets === [])
        {
            throw new RuntimeException("config: 'targets' must be a non-empty object");
        }

        $defaults = $data['defaults'] ?? [];

        if ( ! is_array($defaults))
        {
            throw new RuntimeException("config: 'defaults' must be an object");
        }

        $root = realpath(dirname($file));

        if ($root === false)
        {
            throw new RuntimeException("config directory could not be resolved: {$file}");
        }

        $config = new self();
        $config->operator = self::buildOperator($data['operator'] ?? null);

        foreach ($targets as $name => $target)
        {
            if ( ! is_string($name) || ! in_array($name, self::TARGETS, true))
            {
                throw new RuntimeException(
                    "config: invalid target '{$name}'. use: ". implode(', ', self::TARGETS),
                );
            }

            if ( ! is_array($target))
            {
                throw new RuntimeException("config: target '{$name}' must be an object");
            }

            $merged = self::merge($defaults, $target);
            $config->targets[$name] = self::buildTarget($name, $merged, $root);
        }

        self::assertUniqueAddresses($config->targets);
        $config->targets = self::withNestedWebroots($config->targets);

        return $config;
    }


    public function operator(): ?Operator
    {
        return $this->operator;
    }


    public function requireOperator(): Operator
    {
        if ($this->operator === null) {
            throw new RuntimeException('config: operator.name is required');
        }

        return $this->operator;
    }


    public function target(string $name): Target
    {
        if ( ! isset($this->targets[$name]))
        {
            throw new RuntimeException(
                "unknown target '{$name}'. available: ". implode(', ', $this->names()),
            );
        }

        return $this->targets[$name];
    }


    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->targets);
    }


    /**
     * @param array<array-key, mixed> $defaults
     * @param array<array-key, mixed> $target
     * @return array<array-key, mixed>
     */
    private static function merge(array $defaults, array $target): array
    {
        foreach ($target as $key => $value)
        {
            if (
                is_array($value)
                && isset($defaults[$key])
                && is_array($defaults[$key])
                && self::isAssoc($value)
                && self::isAssoc($defaults[$key])
            ) {
                $defaults[$key] = self::merge($defaults[$key], $value);
                continue;
            }

            $defaults[$key] = $value;
        }

        return $defaults;
    }


    /**
     * @param array<array-key, mixed> $value
     */
    private static function isAssoc(array $value): bool
    {
        return $value === [] || ! array_is_list($value);
    }


    private static function buildOperator(mixed $value): ?Operator
    {
        if ($value === null) {
            return null;
        }

        if ( ! is_array($value)) {
            throw new RuntimeException("config: 'operator' must be an object");
        }

        $name = $value['name'] ?? null;

        if ( ! is_string($name) || trim($name) === '') {
            throw new RuntimeException("config: 'operator.name' must be a non-empty string");
        }

        $email = $value['email'] ?? null;

        if ($email !== null && ( ! is_string($email) || trim($email) === '')) {
            throw new RuntimeException("config: 'operator.email' must be a non-empty string");
        }

        return new Operator(trim($name), is_string($email) ? trim($email) : null);
    }


    /** @param array<array-key, mixed> $data */
    private static function buildTarget(string $name, array $data, string $root): Target
    {
        $ssh = self::requireArray($data, 'ssh');

        $host = self::requireString($ssh, 'host');
        $user = self::requireString($ssh, 'user');
        $port = self::optionalInt($ssh, 'port', 22);
        $identity = self::optionalString($ssh, 'identity');

        if ($identity !== null)
        {
            $identity = self::resolveIdentity($identity, $root);
        }

        if ($port < 1 || $port > 65535)
        {
            throw new RuntimeException("config: target '{$name}' has an invalid SSH port");
        }

        $webroot = self::path($name, 'webroot', self::requireString($data, 'webroot'));
        $root = self::rootPath($name, $data['root'] ?? null);
        $rootDirectory = $root['root'];

        if ($rootDirectory === $webroot)
        {
            throw new RuntimeException("config: target '{$name}' must not use the same 'root' and 'webroot'");
        }

        if (str_starts_with($rootDirectory .'/', $webroot .'/'))
        {
            throw new RuntimeException("config: target '{$name}' must not place 'root' inside 'webroot'");
        }

        $url = self::requireString($data, 'url');

        if (filter_var($url, FILTER_VALIDATE_URL) === false)
        {
            throw new RuntimeException("config: target '{$name}' has an invalid 'url'");
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ( ! is_string($scheme) || ! in_array($scheme, ['http', 'https'], true))
        {
            throw new RuntimeException("config: target '{$name}' requires an http or https 'url'");
        }

        $preserve = self::stringList($data['preserve'] ?? []);

        foreach ($preserve as $pattern)
        {
            if ( ! str_starts_with($pattern, '/'))
            {
                throw new RuntimeException("config: target '{$name}' has an invalid preserve pattern '{$pattern}'");
            }
        }

        $keep = $data['keep'] ?? [];

        if ( ! is_array($keep)) {
            throw new RuntimeException("config: 'keep' must be an object");
        }

        $backupKeep = self::keep($keep, 'backup', 3);
        $releaseKeep = self::keep($keep, 'release', 10);
        $eventKeep = self::keep($keep, 'event', 200);

        return new Target(
            $name,
            $host,
            $user,
            $port,
            $identity,
            $webroot,
            $rootDirectory,
            $root['base'],
            $root['dir'],
            $url,
            $preserve,
            $backupKeep,
            [],
            $releaseKeep,
            $eventKeep,
        );
    }


    private static function path(string $name, string $key, string $value): string
    {
        if ( ! str_starts_with($value, '/'))
        {
            throw new RuntimeException("config: target '{$name}' requires an absolute '{$key}'");
        }

        $path = rtrim($value, '/');

        if ($path === '')
        {
            throw new RuntimeException("config: target '{$name}' has a root-level '{$key}'");
        }

        if (str_contains($path, '\\') || str_contains($path, '~'))
        {
            throw new RuntimeException("config: target '{$name}' has an invalid '{$key}'");
        }

        if (preg_match('#(^|/)\.{1,2}(/|$)#', $path) === 1)
        {
            throw new RuntimeException("config: target '{$name}' has a traversal in '{$key}'");
        }

        return $path;
    }


    /**
     * @return array{root:string, base:string, dir:?string}
     */
    private static function rootPath(string $name, mixed $value): array
    {
        if ( ! is_array($value))
        {
            throw new RuntimeException("config: target '{$name}' requires a 'root' object");
        }

        $base = self::requireString($value, 'base');
        $path = self::path($name, 'root.base', $base);
        $directory = $value['dir'] ?? null;

        if ($directory === null)
        {
            return ['root' => $path, 'base' => $path, 'dir' => null];
        }

        if ( ! is_string($directory) || trim($directory) === '')
        {
            throw new RuntimeException("config: 'root.dir' must be a non-empty string");
        }

        $directory = trim($directory);

        if (
            $directory === '.'
            || $directory === '..'
            || str_contains($directory, '/')
            || str_contains($directory, '\\')
            || str_contains($directory, '~')
        ) {
            throw new RuntimeException("config: target '{$name}' has an invalid 'root.dir'");
        }

        return ['root' => $path .'/'. $directory, 'base' => $path, 'dir' => $directory];
    }


    /**
     * @param array<string, Target> $targets
     */
    private static function assertUniqueAddresses(array $targets): void
    {
        $names = array_keys($targets);

        foreach ($names as $leftIndex => $leftName)
        {
            $left = $targets[$leftName];

            foreach (array_slice($names, $leftIndex + 1) as $rightName)
            {
                $right = $targets[$rightName];

                if ( ! self::sameServer($left, $right))
                {
                    continue;
                }

                if ($left->root === $right->root)
                {
                    throw new RuntimeException(
                        "config: targets '{$leftName}' and '{$rightName}' must not share root '{$left->root}' on the same server",
                    );
                }

                if ($left->webroot === $right->webroot)
                {
                    throw new RuntimeException(
                        "config: targets '{$leftName}' and '{$rightName}' must not share webroot '{$left->webroot}' on the same server",
                    );
                }

                if (self::normalizeUrl($left->url) === self::normalizeUrl($right->url))
                {
                    throw new RuntimeException(
                        "config: targets '{$leftName}' and '{$rightName}' must not share url '{$left->url}' on the same server",
                    );
                }
            }
        }
    }


    /**
     * @param array<string, Target> $targets
     * @return array<string, Target>
     */
    private static function withNestedWebroots(array $targets): array
    {
        $result = [];

        foreach ($targets as $name => $target)
        {
            $nested = [];

            foreach ($targets as $otherName => $other)
            {
                if ($name === $otherName || ! self::sameServer($target, $other))
                {
                    continue;
                }

                $prefix = $target->webroot .'/';

                if ( ! str_starts_with($other->webroot, $prefix))
                {
                    continue;
                }

                $relative = substr($other->webroot, strlen($prefix));

                if ($relative === '')
                {
                    continue;
                }

                if (str_contains($relative, '/'))
                {
                    throw new RuntimeException(
                        "config: target '{$otherName}' webroot must be a direct child of '{$name}' webroot or outside it",
                    );
                }

                $nested[] = $relative;
            }

            $result[$name] = new Target(
                $target->name,
                $target->sshHost,
                $target->sshUser,
                $target->sshPort,
                $target->sshIdentity,
                $target->webroot,
                $target->root,
                $target->rootBase,
                $target->rootDir,
                $target->url,
                $target->preserve,
                $target->backupKeep,
                array_values(array_unique($nested)),
                $target->releaseKeep,
                $target->eventKeep,
            );
        }

        return $result;
    }


    private static function sameServer(Target $left, Target $right): bool
    {
        return strtolower($left->sshHost) === strtolower($right->sshHost)
            && $left->sshPort === $right->sshPort;
    }


    private static function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if ( ! is_array($parts))
        {
            return rtrim($url, '/');
        }

        $scheme = strtolower(is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '');
        $host = strtolower(is_string($parts['host'] ?? null) ? $parts['host'] : '');
        $port = is_int($parts['port'] ?? null) ? ':'. $parts['port'] : '';
        $path = rtrim(is_string($parts['path'] ?? null) ? $parts['path'] : '', '/');

        return $scheme .'://'. $host . $port . $path;
    }


    private static function resolveIdentity(string $identity, string $root): string
    {
        if (str_starts_with($identity, '~/') || str_starts_with($identity, '/'))
        {
            return $identity;
        }

        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $identity)) as $segment)
        {
            if ($segment === '' || $segment === '.')
            {
                continue;
            }

            if ($segment === '..')
            {
                if ($segments === [])
                {
                    throw new RuntimeException("config: ssh.identity escapes project root: {$identity}");
                }

                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        if ($segments === [])
        {
            throw new RuntimeException("config: invalid ssh.identity: {$identity}");
        }

        return rtrim($root, '/') .'/'. implode('/', $segments);
    }


    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function requireArray(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if ( ! is_array($value))
        {
            throw new RuntimeException("config: '{$key}' must be an object");
        }

        return $value;
    }


    /**
     * @param array<array-key, mixed> $data
     */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if ( ! is_string($value) || trim($value) === '')
        {
            throw new RuntimeException("config: '{$key}' must be a non-empty string");
        }

        return trim($value);
    }


    /**
     * @param array<array-key, mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null)
        {
            return null;
        }

        if ( ! is_string($value) || trim($value) === '')
        {
            throw new RuntimeException("config: '{$key}' must be a non-empty string");
        }

        return trim($value);
    }


    /**
     * @param array<array-key, mixed> $data
     */
    private static function optionalInt(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? $default;

        if ( ! is_int($value))
        {
            throw new RuntimeException("config: '{$key}' must be an integer");
        }

        return $value;
    }


    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if ( ! is_array($value))
        {
            throw new RuntimeException('config: invalid list');
        }

        $items = [];

        foreach ($value as $item)
        {
            if ( ! is_string($item))
            {
                throw new RuntimeException('config: list item must be a string');
            }

            $items[] = $item;
        }

        return $items;
    }


    /** @param array<array-key, mixed> $keep */
    private static function keep(array $keep, string $key, int $default): int
    {
        $value = $keep[$key] ?? $default;

        if ( ! is_int($value) || $value < 1) {
            throw new RuntimeException("config: 'keep.{$key}' must be an integer >= 1");
        }

        return $value;
    }

}
