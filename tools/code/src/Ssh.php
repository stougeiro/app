<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code;

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
            ...$target->sshAuth->prefix('ssh'),
            '-p', (string) $target->sshPort,
            ...$target->sshAuth->options(),
            '-o', 'ConnectTimeout=15',
        ];

        $command[] = $target->sshTarget();
        $command[] = $remote;

        return $command;
    }


    public function run(Target $target, string $remote, ?string $stdin = null): ShellResult
    {
        return $this->shell->run(
            $this->argv($target, $remote),
            $stdin,
            null,
            $target->sshAuth->env(),
        );
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
        return Shell::quote($value);
    }
}
