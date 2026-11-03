<?php declare(strict_types=1);

namespace Tests\Feature\Deploy;

use Deploy\Shell;
use Deploy\ShellResult;
use RuntimeException;


final class FakeShell extends Shell
{
    /** @var list<array{command:list<string>,stdin:?string,cwd:?string,env:?array<string,string>}> */
    public array $calls = [];

    /** @var callable(list<string>, ?string, ?string, ?array<string,string>): ShellResult */
    private $handler;


    /**
     * @param callable(list<string>, ?string, ?string, ?array<string,string>): ShellResult $handler
     */
    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }


    public function run(array $command, ?string $stdin = null, ?string $cwd = null, ?array $env = null): ShellResult
    {
        $this->calls[] = [
            'command' => $command,
            'stdin' => $stdin,
            'cwd' => $cwd,
            'env' => $env,
        ];

        $result = ($this->handler)($command, $stdin, $cwd, $env);

        if ( ! $result instanceof ShellResult)
        {
            throw new RuntimeException('fake shell handler must return ShellResult');
        }

        return $result;
    }


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
}
