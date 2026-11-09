<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Lock;

use RuntimeException;
use Sourcerer\Tool\Contract\Operator;
use Sourcerer\Tool\Code\Shell;
use Sourcerer\Tool\Code\Ssh;
use Sourcerer\Tool\Code\Target;


final class TargetLock
{
    private const TTL = 10800;

    private readonly Ssh $ssh;

    private bool $active = false;


    public function __construct(
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly Operator $operator,
        private readonly string $action,
    ) {
        $this->ssh = new Ssh($shell);
    }


    /**
     * Returns the previous owner when the lock was taken with --force.
     *
     * @return null|array<string, mixed>
     */
    public function acquire(bool $force = false): ?array
    {
        if ($this->target->name !== 'production') {
            return null;
        }

        $directory = $this->directory();
        $previous = null;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $result = $this->ssh->run(
                $this->target,
                'mkdir -p '. Ssh::quote($this->target->root .'/.deploy')
                .' && mkdir '. Ssh::quote($directory) .' 2>/dev/null && echo __LOCKED__ || echo __EXISTS__',
            );

            if (str_contains($result->stdout, '__LOCKED__')) {
                $this->writeOwner($directory);
                $this->active = true;

                return $previous;
            }

            $owner = $this->readOwner($directory);
            $startedAt = $owner['startedAtEpoch'] ?? null;
            $now = (int) trim($this->ssh->run($this->target, 'date +%s')->stdout);
            $now = $now > 0 ? $now : time();
            $age = is_int($startedAt) ? $now - $startedAt : null;

            if ( ! $force && ($age === null || $age < self::TTL)) {
                $by = $owner['by'] ?? null;
                $name = is_array($by) && is_string($by['name'] ?? null) ? $by['name'] : 'unknown';
                $since = is_string($owner['startedAt'] ?? null) ? $owner['startedAt'] : 'unknown';

                throw new RuntimeException(
                    "target 'production' is locked by {$name} since {$since}",
                );
            }

            if ($force) {
                $previous = $owner;
            }

            $this->ssh->must($this->target, 'rm -rf '. Ssh::quote($directory));
        }

        throw new RuntimeException("could not acquire production lock");
    }


    public function release(): void
    {
        if ( ! $this->active) {
            return;
        }

        $this->ssh->run($this->target, 'rm -rf '. Ssh::quote($this->directory()));
        $this->active = false;
    }


    private function writeOwner(string $directory): void
    {
        $epoch = (int) trim($this->ssh->must($this->target, 'date +%s')->stdout);

        $data = [
            'by' => $this->operator->toArray(),
            'host' => gethostname() ?: 'unknown',
            'pid' => getmypid(),
            'action' => $this->action,
            'startedAt' => gmdate('c', $epoch),
            'startedAtEpoch' => $epoch,
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ."\n";

        $this->ssh->must(
            $this->target,
            'cat > '. Ssh::quote($directory .'/owner.json'),
            $json,
        );
    }


    /** @return array<string, mixed> */
    private function readOwner(string $directory): array
    {
        $result = $this->ssh->run(
            $this->target,
            'cat '. Ssh::quote($directory .'/owner.json') .' 2>/dev/null || true',
        );

        $data = json_decode($result->stdout, true);

        return is_array($data) ? $data : [];
    }


    private function directory(): string
    {
        return $this->target->root .'/.deploy/lock';
    }
}
