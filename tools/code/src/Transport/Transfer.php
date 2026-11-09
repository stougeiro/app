<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Transport;

use Sourcerer\Tool\Contract\OutputInterface;
use Sourcerer\Tool\Code\Remote\Scp;
use Sourcerer\Tool\Code\Shell;
use Sourcerer\Tool\Code\Ssh;
use Sourcerer\Tool\Support\FileSystem;
use Sourcerer\Tool\Code\Support\Paths;
use Sourcerer\Tool\Code\Target;


final class Transfer
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly Ssh $ssh,
        private readonly Scp $scp,
        private readonly OutputInterface $output,
    ) {
    }


    public function run(string $vendor, bool $hasRsync): void
    {
        $this->ensureRemoteDirs();

        if ($hasRsync)
        {
            $this->transferRsync($vendor);
        }
        else
        {
            $this->transferTar($vendor);
        }

        $this->applyOverrides($hasRsync);
    }


    public function ensureRemoteDirs(): void
    {
        $target = $this->target;
        $directories = [
            $target->root .'/application',
            $target->root .'/application/storage/cache',
            $target->root .'/application/storage/log',
            $target->root .'/application/storage/session',
            $target->root .'/application/storage/temp',
            $target->root .'/application/storage/upload',
            $target->root .'/vendor',
            $target->root .'/.deploy',
            $target->root .'/.deploy/backups',
            $target->root .'/.deploy/releases',
            $target->root .'/.deploy/events',
        ];

        $command = 'mkdir -p';
        foreach ($directories as $directory)
        {
            $command .= ' '. Ssh::quote($directory);
        }

        $this->ssh->must($target, $command);
    }


    public function applyOverrides(bool $hasRsync): void
    {
        foreach ([
            ['application', Paths::override($this->projectRoot, $this->target->name, 'application'), $this->target->root .'/application'],
            ['public', Paths::override($this->projectRoot, $this->target->name, 'public'), $this->target->webroot],
        ] as [$label, $source, $destination])
        {
            if ( ! is_dir($source))
            {
                continue;
            }

            $this->out('override '. $label);

            if ($hasRsync)
            {
                $this->shell->mustRun(
                    $this->rsyncArgs($source .'/', $destination .'/', [], false, false),
                );
                continue;
            }

            $this->transferTarDirectory($source, $destination);
        }
    }


    private function transferRsync(string $vendor): void
    {
        $this->out('rsync application');
        $this->shell->mustRun(
            $this->rsyncArgs(
                $this->projectRoot .'/application/',
                $this->target->root .'/application/',
                $this->applicationExcludes(),
                false,
            ),
        );

        $this->out('rsync public');
        $this->shell->mustRun(
            $this->rsyncArgs(
                $this->projectRoot .'/public/',
                $this->target->webroot .'/',
                $this->publicExcludes(),
                false,
            ),
        );

        $this->out('rsync vendor');
        $this->shell->mustRun(
            $this->rsyncArgs($vendor .'/', $this->target->root .'/vendor/', [], false),
        );
    }


    private function transferTar(string $vendor): void
    {
        $target = $this->target;
        $rootArchive = FileSystem::temporaryArchive();
        $webrootArchive = FileSystem::temporaryArchive();
        $rootRemote = $target->root .'/.deploy/incoming-root-'. bin2hex(random_bytes(4)) .'.tar.gz';
        $webrootRemote = $target->root .'/.deploy/incoming-webroot-'. bin2hex(random_bytes(4)) .'.tar.gz';

        try
        {
            $this->shell->mustRun([
                'tar', '-czf', $rootArchive,
                '--exclude=application/env.json',
                '--exclude=application/storage',
                '-C', $this->projectRoot,
                'application',
                '-C', dirname($vendor),
                'vendor',
            ]);

            $webrootCommand = ['tar', '-czf', $webrootArchive, '--exclude=.maintenance'];

            foreach ($target->nestedWebroots as $entry)
            {
                $webrootCommand[] = '--exclude=./'. $entry;
            }

            array_push($webrootCommand, '-C', $this->projectRoot .'/public', '.');

            $this->shell->mustRun($webrootCommand);

            $this->out('tar fallback: uploading');
            $this->scp->upload($rootArchive, $rootRemote);
            $this->scp->upload($webrootArchive, $webrootRemote);

            $this->ssh->must(
                $target,
                'tar -xzf '. Ssh::quote($rootRemote) .' -C '. Ssh::quote($target->root)
                .' && rm -f '. Ssh::quote($rootRemote)
                .' && tar -xzf '. Ssh::quote($webrootRemote) .' -C '. Ssh::quote($target->webroot)
                .' && rm -f '. Ssh::quote($webrootRemote),
            );
        }
        finally
        {
            if ( ! FileSystem::tryDelete($rootArchive)) {
                $this->out('warning: could not remove temporary root archive');
            }

            if ( ! FileSystem::tryDelete($webrootArchive)) {
                $this->out('warning: could not remove temporary webroot archive');
            }
        }
    }


    private function transferTarDirectory(string $source, string $destination): void
    {
        $archive = FileSystem::temporaryArchive();
        $remote = $this->target->root .'/.deploy/incoming-'. bin2hex(random_bytes(4)) .'.tar.gz';

        try
        {
            $this->shell->mustRun(['tar', '-czf', $archive, '-C', $source, '.']);
            $this->scp->upload($archive, $remote);

            $this->ssh->must(
                $this->target,
                'tar -xzf '. Ssh::quote($remote) .' -C '. Ssh::quote($destination)
                .' && rm -f '. Ssh::quote($remote),
            );
        }
        finally
        {
            if ( ! FileSystem::tryDelete($archive)) {
                $this->out('warning: could not remove temporary archive');
            }
        }
    }


    /**
     * @return list<string>
     */
    private function applicationExcludes(): array
    {
        return ['/env.json', '/storage/'];
    }


    /**
     * @return list<string>
     */
    private function publicExcludes(): array
    {
        $excludes = ['/.maintenance', ...$this->target->preserve];

        foreach ($this->target->nestedWebroots as $entry)
        {
            $excludes[] = '/'. $entry;
            $excludes[] = '/'. $entry .'/***';
        }

        return $excludes;
    }


    /**
     * @param list<string> $excludes
     * @return list<string>
     */
    private function rsyncArgs(
        string $source,
        string $destination,
        array $excludes,
        bool $dryRun,
        bool $delete = true,
    ): array {
        $target = $this->target;
        $ssh = 'ssh -p '. $target->sshPort .' -o BatchMode=yes -o ConnectTimeout=15';
        $identity = $target->identityPath();

        if ($identity !== null)
        {
            $ssh .= ' -i '. escapeshellarg($identity);
        }

        $args = [
            'rsync', '-az',
            '--checksum',
            '--delay-updates',
            '--protect-args',
            '--itemize-changes',
            '-e', $ssh,
        ];

        if ($delete)
        {
            $args[] = '--delete';
        }

        if ($dryRun)
        {
            $args[] = '--dry-run';
        }

        foreach ($excludes as $exclude)
        {
            $args[] = '--exclude='. $exclude;
        }

        $args[] = $source;
        $args[] = $target->sshTarget() .':'. $destination;

        return $args;
    }


    private function out(string $message): void
    {
        $this->output->line('['. $this->target->name .'] '. $message);
    }
}
