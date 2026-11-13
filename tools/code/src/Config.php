<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code;

use JsonException;
use RuntimeException;
use Sourcerer\Tool\Code\Config\TargetFactory;
use Sourcerer\Tool\Code\Config\TargetGraph;


class Config
{
    /**
     * Ordered deploy targets. Promotion follows this exact order.
     *
     * @var list<string>
     */
    public const TARGETS = ['development', 'staging', 'production'];

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

        $defaults = $data['global'] ?? [];

        if ( ! is_array($defaults))
        {
            throw new RuntimeException("config: 'global' must be an object");
        }

        $root = realpath(dirname($file));

        if ($root === false)
        {
            throw new RuntimeException("config directory could not be resolved: {$file}");
        }

        $config = new self();

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
            if ($key === 'auth') {
                $defaults[$key] = $value;
                continue;
            }

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


}
