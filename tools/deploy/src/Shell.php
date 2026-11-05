<?php declare(strict_types=1);

namespace Tool\Deploy;

use RuntimeException;


class Shell
{
    /**
     * @param list<string> $command
     * @param array<string, string>|null $env
     */
    public function run(array $command, ?string $stdin = null, ?string $cwd = null, ?array $env = null): ShellResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        /** @var array<int, resource> $pipes */
        $pipes = [];

        $process = proc_open($command, $descriptors, $pipes, $cwd, $env);

        if ( ! is_resource($process))
        {
            throw new RuntimeException('could not execute: '. implode(' ', $command));
        }

        if ($stdin !== null)
        {
            fwrite($pipes[0], $stdin);
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        return new ShellResult(
            $code,
            $stdout === false ? '' : $stdout,
            $stderr === false ? '' : $stderr,
        );
    }


    /**
     * @param list<string> $command
     * @param array<string, string>|null $env
     */
    public function mustRun(array $command, ?string $stdin = null, ?string $cwd = null, ?array $env = null): ShellResult
    {
        $result = $this->run($command, $stdin, $cwd, $env);

        if ( ! $result->ok())
        {
            $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);

            throw new RuntimeException(
                'command failed ('. $result->code .'): '. implode(' ', $command)
                . ($detail !== '' ? "\n". $detail : ''),
            );
        }

        return $result;
    }


    public static function quote(string $value): string
    {
        return escapeshellarg($value);
    }
}
