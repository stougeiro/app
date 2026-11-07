<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Remote;

use Sourcerer\Tool\Code\Shell;
use Sourcerer\Tool\Code\Target;


final class Scp
{
    public function __construct(
        private readonly Target $target,
        private readonly Shell $shell,
    ) {
    }


    public function upload(string $local, string $remote): void
    {
        $this->shell->mustRun($this->argv($local, $this->target->sshTarget() .':'. $remote));
    }


    public function download(string $remote, string $local): void
    {
        $this->shell->mustRun($this->argv($this->target->sshTarget() .':'. $remote, $local));
    }


    /**
     * @return list<string>
     */
    private function argv(string $source, string $destination): array
    {
        $target = $this->target;
        $scp = [
            'scp',
            '-P', (string) $target->sshPort,
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=15',
        ];

        $identity = $target->identityPath();

        if ($identity !== null)
        {
            $scp[] = '-i';
            $scp[] = $identity;
        }

        $scp[] = $source;
        $scp[] = $destination;

        return $scp;
    }
}
