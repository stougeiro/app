<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Config;


final class TargetConfig
{
    public function __construct(
        public readonly string $name,
        public readonly string $transport,
        public readonly string $database,
        public readonly string $driver,
        public readonly ?string $host = null,
        public readonly ?int $port = null,
        public readonly ?string $user = null,
        public readonly ?string $password = null,
        public readonly ?string $sshHost = null,
        public readonly ?int $sshPort = null,
        public readonly ?string $sshUser = null,
        public readonly ?string $sshIdentity = null,
        public readonly ?string $phpBinary = null,
    ) {
    }


    public function isLocal(): bool
    {
        return $this->transport === 'local';
    }

    public function isSsh(): bool
    {
        return $this->transport === 'ssh';
    }
}
