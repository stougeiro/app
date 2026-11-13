<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code;

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
        public readonly SshAuth $sshAuth,
        public readonly string $webroot,
        public readonly string $root,
        public readonly string $rootBase,
        public readonly ?string $rootDir,
        public readonly string $url,
        public readonly array $preserve,
        public readonly int $backupKeep,
        public readonly array $nestedWebroots = [],
        public readonly int $releaseKeep = 10,
        public readonly int $eventKeep = 200,
    ) {
    }


    public function sshTarget(): string
    {
        return $this->sshUser .'@'. $this->sshHost;
    }


}
