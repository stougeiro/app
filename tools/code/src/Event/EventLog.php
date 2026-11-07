<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Event;

use JsonException;
use RuntimeException;
use Sourcerer\Tool\Code\Remote\Scp;
use Sourcerer\Tool\Code\Shell;
use Sourcerer\Tool\Code\Ssh;
use Sourcerer\Tool\Code\Target;


final class EventLog
{
    private readonly Ssh $ssh;


    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
    ) {
        $this->ssh = new Ssh($shell);
    }


    public function write(DeployEvent $event): string
    {
        $name = gmdate('Ymd\THis\Z')
            .'-'. $event->target
            .'-'. $event->action
            .'-'. $event->releaseId
            .'-'. bin2hex(random_bytes(3))
            .'.json';

        try {
            $json = json_encode(
                $event->toArray(),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ) ."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('could not encode deploy event: '. $exception->getMessage(), 0, $exception);
        }

        $this->ensureLocalDirectory();
        $this->ssh->must($this->target, 'mkdir -p '. Ssh::quote($this->remoteDirectory()));
        $this->ssh->must(
            $this->target,
            'cat > '. Ssh::quote($this->remotePath($name)),
            $json,
        );

        if (file_put_contents($this->localPath($name), $json) === false) {
            throw new RuntimeException("could not write local deploy event: {$name}");
        }

        return $name;
    }


    /** @return list<string> */
    public function remoteNames(): array
    {
        $result = $this->ssh->must(
            $this->target,
            'ls -1 '. Ssh::quote($this->remoteDirectory()) .'/*.json 2>/dev/null || true',
        );

        $names = [];

        foreach (explode("\n", trim($result->stdout)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $names[] = basename(trim($line));
        }

        sort($names, SORT_STRING);

        return $names;
    }


    public function download(string $name): void
    {
        $this->ensureLocalDirectory();
        $this->downloadFile($this->remotePath($name), $this->localPath($name));
    }


    public function prune(int $keep): void
    {
        $keep = max(1, $keep);

        $this->ssh->run(
            $this->target,
            'ls -1t '. Ssh::quote($this->remoteDirectory()) .'/*.json 2>/dev/null'
            .' | tail -n +'. ($keep + 1)
            .' | xargs -r rm -f',
        );
    }


    public function localDirectory(): string
    {
        return rtrim($this->projectRoot, '/') .'/.deploy/events';
    }

    public function localPath(string $name): string
    {
        return $this->localDirectory() .'/'. $name;
    }

    public function remoteDirectory(): string
    {
        return $this->target->root .'/.deploy/events';
    }

    public function remotePath(string $name): string
    {
        return $this->remoteDirectory() .'/'. $name;
    }


    private function ensureLocalDirectory(): void
    {
        $directory = $this->localDirectory();

        if ( ! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("could not create local event directory: {$directory}");
        }
    }


    private function downloadFile(string $remote, string $local): void
    {
        (new Scp($this->target, $this->shell))->download($remote, $local);
    }
}
