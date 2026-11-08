<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Config;

use RuntimeException;


final class DatabaseConfig
{
    /**
     * @param array<string, TargetConfig> $targets
     */
    public function __construct(
        public readonly string $name,
        public readonly string $driver,
        public readonly ?string $version,
        private readonly array $targets,
    ) {
    }


    public function target(string $name): TargetConfig
    {
        if ( ! isset($this->targets[$name])) {
            throw new RuntimeException(
                "schema: database '{$this->name}' has no target '{$name}'. available: ". implode(', ', $this->names()),
            );
        }

        return $this->targets[$name];
    }


    public function local(): TargetConfig
    {
        return $this->target('local');
    }


    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->targets);
    }
}
