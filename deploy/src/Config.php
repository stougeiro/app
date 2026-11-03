<?php declare(strict_types=1);

namespace Deploy;

use JsonException;
use RuntimeException;


class Config
{
    /** @var list<string> */
    public const ENVIRONMENTS = ['development', 'staging', 'production'];

    /** @var array<string, Target> */
    private array $targets = [];


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

        foreach ($targets as $name => $target)
        {
            if ( ! is_string($name) || ! in_array($name, self::ENVIRONMENTS, true))
            {
                throw new RuntimeException(
                    "config: invalid target '{$name}'. use: ". implode(', ', self::ENVIRONMENTS),
                );
            }

            if ( ! is_array($target))
            {
                throw new RuntimeException("config: target '{$name}' must be an object");
            }

            $merged = self::merge($defaults, $target);
            $config->targets[$name] = self::buildTarget($name, $merged, $root);
        }

        self::assertProductionUniqueness($config->targets);

        return $config;
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


    /**
     * @param array<array-key, mixed> $data
     */
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

        $webroot = rtrim(self::requireString($data, 'webroot'), '/');

        if ( ! str_starts_with($webroot, '/'))
        {
            throw new RuntimeException("config: target '{$name}' requires an absolute 'webroot'");
        }

        if ($webroot === '/')
        {
            throw new RuntimeException("config: target '{$name}' has a root-level 'webroot'");
        }

        $rootDirectory = rtrim(self::requireString($data, 'root'), '/');

        if ( ! str_starts_with($rootDirectory, '/'))
        {
            throw new RuntimeException("config: target '{$name}' requires an absolute 'root'");
        }

        if ($rootDirectory === '/')
        {
            throw new RuntimeException("config: target '{$name}' has a root-level 'root'");
        }

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

        $permissions = self::permissions($data['permissions'] ?? []);
        $backup = self::backup($data['backup'] ?? []);
        $requiresPlan = self::optionalBool($data, 'requiresPlan', false);

        return new Target(
            $name,
            $host,
            $user,
            $port,
            $identity,
            $webroot,
            $rootDirectory,
            $url,
            $preserve,
            $permissions,
            $backup['enabled'],
            $backup['keep'],
            $requiresPlan,
        );
    }


    /**
     * @param array<string, Target> $targets
     */
    private static function assertProductionUniqueness(array $targets): void
    {
        $production = $targets['production'] ?? null;

        if ($production === null)
        {
            return;
        }

        foreach ($targets as $name => $target)
        {
            if ($name === 'production' || ! self::sameServer($production, $target))
            {
                continue;
            }

            if (self::normalizeUrl($target->url) === self::normalizeUrl($production->url))
            {
                throw new RuntimeException(
                    "config: target '{$name}' must not reuse production url '{$target->url}' on the same server",
                );
            }

            if ($target->root === $production->root)
            {
                throw new RuntimeException(
                    "config: target '{$name}' must not reuse production root '{$target->root}' on the same server",
                );
            }

            if ($target->webroot === $production->webroot)
            {
                throw new RuntimeException(
                    "config: target '{$name}' must not reuse production webroot '{$target->webroot}' on the same server",
                );
            }
        }
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
     * @param array<array-key, mixed> $data
     */
    private static function optionalBool(array $data, string $key, bool $default): bool
    {
        $value = $data[$key] ?? $default;

        if ( ! is_bool($value))
        {
            throw new RuntimeException("config: '{$key}' must be a boolean");
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


    /**
     * @return array{dir:string,file:string,storage:string}
     */
    private static function permissions(mixed $value): array
    {
        if ( ! is_array($value))
        {
            throw new RuntimeException("config: 'permissions' must be an object");
        }

        return [
            'dir' => self::permission($value['dir'] ?? '755', 'permissions.dir'),
            'file' => self::permission($value['file'] ?? '644', 'permissions.file'),
            'storage' => self::permission($value['storage'] ?? '775', 'permissions.storage'),
        ];
    }


    private static function permission(mixed $value, string $key): string
    {
        if ( ! is_string($value) || preg_match('/\A[0-7]{3,4}\z/', $value) !== 1)
        {
            throw new RuntimeException("config: '{$key}' must be octal (e.g. 755)");
        }

        return $value;
    }


    /**
     * @return array{enabled:bool,keep:int}
     */
    private static function backup(mixed $value): array
    {
        if ( ! is_array($value))
        {
            throw new RuntimeException("config: 'backup' must be an object");
        }

        $enabled = $value['enabled'] ?? true;
        $keep = $value['keep'] ?? 3;

        if ( ! is_bool($enabled))
        {
            throw new RuntimeException("config: 'backup.enabled' must be a boolean");
        }

        if ( ! is_int($keep) || $keep < 1)
        {
            throw new RuntimeException("config: 'backup.keep' must be an integer >= 1");
        }

        return ['enabled' => $enabled, 'keep' => $keep];
    }
}
