<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code;

use JsonException;
use RuntimeException;
use Sourcerer\Tool\Code\Config\TargetFactory;
use Sourcerer\Tool\Code\Config\TargetGraph;
use Sourcerer\Tool\Code\Operator\Operator;


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
            $config->targets[$name] = TargetFactory::build($name, $merged, $root);
        }

        TargetGraph::assertUniqueAddresses($config->targets);
        $config->targets = TargetGraph::withNestedWebroots($config->targets);

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
}
