<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema;

use JsonException;
use RuntimeException;
use Sourcerer\Tool\Schema\Config\DatabaseConfig;
use Sourcerer\Tool\Schema\Config\TargetConfig;


final class Config
{
    /** @var list<string> */
    public const TARGETS = ['local', 'development', 'staging', 'production'];

    /** @var list<string> */
    public const DRIVERS = ['sqlite', 'mysql', 'pgsql'];

    /** @var array<string, DatabaseConfig> */
    private array $databases;


    /** @param array<string, DatabaseConfig> $databases */
    private function __construct(array $databases)
    {
        $this->databases = $databases;
    }


    public static function load(string $projectRoot): self
    {
        $file = rtrim($projectRoot, '/') .'/schema.json';

        if ( ! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException('schema: schema.json not found or unreadable');
        }

        $raw = file_get_contents($file);

        if ($raw === false) {
            throw new RuntimeException('schema: could not read schema.json');
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('schema: invalid schema.json: '. $exception->getMessage(), 0, $exception);
        }

        if ( ! is_array($data)) {
            throw new RuntimeException('schema: schema.json must contain an object');
        }

        $definitions = $data['databases'] ?? null;

        if ( ! is_array($definitions) || $definitions === []) {
            throw new RuntimeException("schema: 'databases' must be a non-empty object");
        }

        $databases = [];

        foreach ($definitions as $name => $definition) {
            if ( ! is_string($name) || preg_match('/\A[a-z][a-z0-9_-]*\z/', $name) !== 1) {
                throw new RuntimeException('schema: invalid database name');
            }

            if ( ! is_array($definition)) {
                throw new RuntimeException("schema: database '{$name}' must be an object");
            }

            $databases[$name] = self::buildDatabase($name, $definition);
        }

        return new self($databases);
    }


    public function database(string $name = 'default'): DatabaseConfig
    {
        if ( ! isset($this->databases[$name])) {
            throw new RuntimeException(
                "schema: unknown database '{$name}'. available: ". implode(', ', $this->names()),
            );
        }

        return $this->databases[$name];
    }


    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->databases);
    }


    /** @param array<array-key, mixed> $definition */
    private static function buildDatabase(string $name, array $definition): DatabaseConfig
    {
        $driver = self::requiredString($definition, 'driver');

        if ( ! in_array($driver, self::DRIVERS, true)) {
            throw new RuntimeException(
                "schema: database '{$name}' has invalid driver '{$driver}'. use: ". implode(', ', self::DRIVERS),
            );
        }

        $version = self::optionalString($definition, 'version');
        $targets = $definition['targets'] ?? null;

        if ( ! is_array($targets) || $targets === []) {
            throw new RuntimeException("schema: database '{$name}' requires a non-empty 'targets' object");
        }

        $parsed = [];

        foreach ($targets as $targetName => $target) {
            if ( ! is_string($targetName) || ! in_array($targetName, self::TARGETS, true)) {
                throw new RuntimeException(
                    "schema: database '{$name}' has invalid target '{$targetName}'. use: ". implode(', ', self::TARGETS),
                );
            }

            if ( ! is_array($target)) {
                throw new RuntimeException("schema: target '{$targetName}' must be an object");
            }

            $parsed[$targetName] = self::target($targetName, $driver, $target);
        }

        if ( ! isset($parsed['local'])) {
            throw new RuntimeException("schema: database '{$name}' requires a 'local' target");
        }

        return new DatabaseConfig($name, $driver, $version, $parsed);
    }


    /** @param array<array-key, mixed> $target */
    private static function target(string $name, string $driver, array $target): TargetConfig
    {
        $transport = self::requiredString($target, 'transport');

        if ( ! in_array($transport, ['local', 'ssh'], true)) {
            throw new RuntimeException(
                "schema: target '{$name}' has invalid transport '{$transport}'. use: local, ssh",
            );
        }

        foreach ($target as $key => $value) {
            if (is_string($key) && $key === 'driver') {
                throw new RuntimeException("schema: target '{$name}' must not override the database driver");
            }
        }

        $database = self::requiredString($target, 'database');
        $host = self::optionalString($target, 'host');
        $port = self::optionalInt($target, 'port', $driver === 'pgsql' ? 5432 : 3306);
        $user = self::optionalString($target, 'user');
        $password = self::optionalString($target, 'password');

        if ($driver !== 'sqlite') {
            if ($host === null || $user === null) {
                throw new RuntimeException("schema: target '{$name}' requires 'host' and 'user'");
            }
        }

        if ($transport === 'local') {
            return new TargetConfig(
                $name,
                $transport,
                $database,
                $driver,
                $host,
                $port,
                $user,
                $password,
            );
        }

        $ssh = $target['ssh'] ?? null;

        if ( ! is_array($ssh)) {
            throw new RuntimeException("schema: target '{$name}' requires an 'ssh' object");
        }

        $sshHost = self::requiredString($ssh, 'host');
        $sshUser = self::requiredString($ssh, 'user');
        $sshPort = self::optionalInt($ssh, 'port', 22);
        $sshIdentity = self::optionalString($ssh, 'identity');
        $phpBinary = self::requiredString($ssh, 'php_binary');

        return new TargetConfig(
            $name,
            $transport,
            $database,
            $driver,
            $host,
            $port,
            $user,
            $password,
            $sshHost,
            $sshPort,
            $sshUser,
            $sshIdentity,
            $phpBinary,
        );
    }


    /** @param array<array-key, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if ( ! is_string($value) || trim($value) === '') {
            throw new RuntimeException("schema: '{$key}' must be a non-empty string");
        }

        return trim($value);
    }


    /** @param array<array-key, mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if ( ! is_string($value) || trim($value) === '') {
            throw new RuntimeException("schema: '{$key}' must be a non-empty string");
        }

        return trim($value);
    }


    /** @param array<array-key, mixed> $data */
    private static function optionalInt(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? $default;

        if ( ! is_int($value) || $value < 1) {
            throw new RuntimeException("schema: '{$key}' must be a positive integer");
        }

        return $value;
    }
}
