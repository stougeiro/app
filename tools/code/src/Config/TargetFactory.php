<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Config;

use RuntimeException;
use Sourcerer\Tool\Code\SshAuth;
use Sourcerer\Tool\Code\Target;


final class TargetFactory
{
    use ConfigValidationTrait;


    /** @param array<array-key, mixed> $data */
    public static function build(string $name, array $data, string $root): Target
    {
        $ssh = self::requireArray($data, 'ssh');

        $host = self::requireString($ssh, 'host');
        $user = self::requireString($ssh, 'user');
        $port = self::optionalInt($ssh, 'port', 22);

        if (($ssh['identity'] ?? null) !== null) {
            throw new RuntimeException(
                "config: target '{$name}' must replace 'ssh.identity' with 'ssh.auth'",
            );
        }

        $auth = self::auth($name, $ssh, $root);

        if ($port < 1 || $port > 65535)
        {
            throw new RuntimeException("config: target '{$name}' has an invalid SSH port");
        }

        $webroot = self::path($name, 'webroot', self::requireString($data, 'webroot'));
        $rootPath = self::rootPath($name, $data['root'] ?? null);
        $rootDirectory = $rootPath['root'];

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
            $auth,
            $webroot,
            $rootDirectory,
            $rootPath['base'],
            $rootPath['dir'],
            $url,
            $preserve,
            $backupKeep,
            [],
            $releaseKeep,
            $eventKeep,
        );
    }


    /** @param array<array-key, mixed> $ssh */
    private static function auth(string $name, array $ssh, string $root): SshAuth
    {
        $auth = $ssh['auth'] ?? null;

        if ( ! is_array($auth)) {
            throw new RuntimeException(
                "config: target '{$name}' requires an 'ssh.auth' object",
            );
        }

        $type = self::requireString($auth, 'type');
        $file = self::requireString($auth, 'file');

        if ( ! in_array($type, [SshAuth::TYPE_KEY, SshAuth::TYPE_PASS], true)) {
            throw new RuntimeException(
                "config: target '{$name}' has an invalid 'ssh.auth.type'; use: key, pass",
            );
        }

        return new SshAuth($type, self::resolveAuthFile($file, $root));
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
}
