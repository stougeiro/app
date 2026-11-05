<?php declare(strict_types=1);

namespace Tool\Deploy\Release;

use RuntimeException;
use Throwable;
use Tool\Deploy\Config;
use Tool\Deploy\Event\EventLog;
use Tool\Deploy\Shell;
use Tool\Deploy\Ssh;
use Tool\Deploy\Target;


final class ReleaseSync
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly Shell $shell,
    ) {
    }


    /**
     * @return array<string, array{ok: bool, manifests: int, events: int, error: ?string}>
     */
    public function syncAll(Config $config): array
    {
        $results = [];

        foreach ($config->names() as $name) {
            $results[$name] = $this->syncTarget($config->target($name));
        }

        return $results;
    }


    /** @return array{ok: bool, manifests: int, events: int, error: ?string} */
    public function syncTarget(Target $target): array
    {
        $ssh = new Ssh($this->shell);

        try {
            $manifests = $this->manifestIds($ssh, $target);
            $events = (new EventLog($this->projectRoot, $target, $this->shell))->remoteNames();

            foreach ($manifests as $releaseId) {
                $local = rtrim($this->projectRoot, '/') .'/.deploy/releases/'. $releaseId .'/manifest.json';

                if (is_file($local)) {
                    continue;
                }

                $this->ensureDirectory(dirname($local));
                $this->download(
                    $target,
                    $target->root .'/.deploy/releases/'. $releaseId .'/manifest.json',
                    $local,
                );
            }

            $eventLog = new EventLog($this->projectRoot, $target, $this->shell);

            foreach ($events as $name) {
                if (is_file($eventLog->localPath($name))) {
                    continue;
                }

                $eventLog->download($name);
            }

            return [
                'ok' => true,
                'manifests' => count($manifests),
                'events' => count($events),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'manifests' => 0,
                'events' => 0,
                'error' => $exception->getMessage(),
            ];
        }
    }


    /** @return list<string> */
    private function manifestIds(Ssh $ssh, Target $target): array
    {
        $result = $ssh->must(
            $target,
            'ls -1 '. Ssh::quote($target->root .'/.deploy/releases') .'/*/manifest.json 2>/dev/null || true',
        );

        $ids = [];

        foreach (explode("\n", trim($result->stdout)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $ids[] = basename(dirname(trim($line)));
        }

        sort($ids, SORT_STRING);

        return $ids;
    }


    private function ensureDirectory(string $directory): void
    {
        if ( ! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("could not create directory: {$directory}");
        }
    }


    private function download(Target $target, string $remote, string $local): void
    {
        $scp = [
            'scp',
            '-P', (string) $target->sshPort,
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=15',
        ];

        $identity = $target->identityPath();

        if ($identity !== null) {
            $scp[] = '-i';
            $scp[] = $identity;
        }

        $scp[] = $target->sshTarget() .':'. $remote;
        $scp[] = $local;

        $this->shell->mustRun($scp);
    }
}
