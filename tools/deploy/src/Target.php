<?php declare(strict_types=1);

namespace Deploy;

use RuntimeException;


class Target
{
    /**
     * @param list<string> $preserve
     * @param array{dir:string,file:string,storage:string} $permissions
     */
    public function __construct(
        public readonly string $name,
        public readonly string $sshHost,
        public readonly string $sshUser,
        public readonly int $sshPort,
        public readonly ?string $sshIdentity,
        public readonly string $webroot,
        public readonly string $root,
        public readonly string $url,
        public readonly array $preserve,
        public readonly array $permissions,
        public readonly bool $backupEnabled,
        public readonly int $backupKeep,
        public readonly bool $requiresPlan,
    ) {
    }


    public function sshTarget(): string
    {
        return $this->sshUser .'@'. $this->sshHost;
    }


    public function identityPath(): ?string
    {
        if ($this->sshIdentity === null || $this->sshIdentity === '')
        {
            return null;
        }

        if (str_starts_with($this->sshIdentity, '~/'))
        {
            $home = getenv('HOME');

            if ($home === false || $home === '')
            {
                throw new RuntimeException('HOME is not set to expand '. $this->sshIdentity);
            }

            return rtrim($home, '/') .'/'. substr($this->sshIdentity, 2);
        }

        return $this->sshIdentity;
    }


    /**
     * @return array<string, mixed>
     */
    public function fingerprintData(): array
    {
        return [
            'name' => $this->name,
            'sshHost' => $this->sshHost,
            'sshUser' => $this->sshUser,
            'sshPort' => $this->sshPort,
            'webroot' => $this->webroot,
            'root' => $this->root,
            'url' => $this->url,
            'preserve' => $this->preserve,
            'permissions' => $this->permissions,
            'backupEnabled' => $this->backupEnabled,
            'backupKeep' => $this->backupKeep,
            'requiresPlan' => $this->requiresPlan,
        ];
    }
}
