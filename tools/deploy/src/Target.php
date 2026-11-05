<?php declare(strict_types=1);

namespace Tool\Deploy;

use RuntimeException;


class Target
{
    /**
     * @param list<string> $preserve
     * @param list<string> $nestedWebroots
     */
    public function __construct(
        public readonly string $name,
        public readonly string $sshHost,
        public readonly string $sshUser,
        public readonly int $sshPort,
        public readonly ?string $sshIdentity,
        public readonly string $webroot,
        public readonly string $root,
        public readonly string $rootBase,
        public readonly ?string $rootDir,
        public readonly string $url,
        public readonly array $preserve,
        public readonly int $backupKeep,
        public readonly array $nestedWebroots = [],
    ) {
    }


    public function sshTarget(): string
    {
        return $this->sshUser .'@'. $this->sshHost;
    }


    public function identityPath(): ?string
    {
        if ($this->sshIdentity === null || $this->sshIdentity === '') {
            return null;
        }

        if (str_starts_with($this->sshIdentity, '~/')) {
            $home = getenv('HOME');

            if ($home === false || $home === '') {
                throw new RuntimeException('HOME is not set to expand '. $this->sshIdentity);
            }

            return rtrim($home, '/') .'/'. substr($this->sshIdentity, 2);
        }

        return $this->sshIdentity;
    }
}
