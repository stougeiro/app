<?php declare(strict_types=1);

namespace Tool\Deploy;

use RuntimeException;


class Ssh
{
    public function __construct(
        private readonly Shell $shell,
    ) {
    }


    /**
     * @return list<string>
     */
    public function argv(Target $target, string $remote): array
    {
        $command = [
            'ssh',
            '-p', (string) $target->sshPort,
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=15',
        ];

        $identity = $target->identityPath();

        if ($identity !== null)
        {
            $command[] = '-i';
            $command[] = $identity;
        }

        $command[] = $target->sshTarget();
        $command[] = $remote;

        return $command;
    }


    public function run(Target $target, string $remote, ?string $stdin = null): ShellResult
    {
        return $this->shell->run($this->argv($target, $remote), $stdin);
    }


    public function must(Target $target, string $remote, ?string $stdin = null): ShellResult
    {
        $result = $this->run($target, $remote, $stdin);

        if ( ! $result->ok())
        {
            $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);

            throw new RuntimeException(
                "ssh {$target->sshTarget()} failed". ($detail !== '' ? ":\n". $detail : ''),
            );
        }

        return $result;
    }


    public static function quote(string $value): string
    {
        return escapeshellarg($value);
    }
}
