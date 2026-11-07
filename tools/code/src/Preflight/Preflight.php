<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Preflight;

use RuntimeException;
use Sourcerer\Tool\Contract\OutputInterface;
use Sourcerer\Tool\Code\Remote\RemoteFiles;
use Sourcerer\Tool\Code\Requirements\ComposerRequirements;
use Sourcerer\Tool\Code\Requirements\WebProbe;
use Sourcerer\Tool\Code\Shell;
use Sourcerer\Tool\Code\Ssh;
use Sourcerer\Tool\Code\Support\Paths;
use Sourcerer\Tool\Code\Target;


final class Preflight
{
    private bool $localEnvExists = false;


    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly Ssh $ssh,
        private readonly RemoteFiles $remote,
        private readonly OutputInterface $output,
    ) {
    }


    public function check(): bool
    {
        $target = $this->target;

        $this->out('check');

        foreach (['composer.json', 'composer.lock'] as $file)
        {
            if ( ! is_file($this->projectRoot .'/'. $file))
            {
                throw new RuntimeException("missing file: {$file}");
            }
        }

        $storage = $target->root .'/application/storage';
        $upload = $storage .'/upload';

        $command = 'test -d '. Ssh::quote($target->rootBase)
            .' && test -w '. Ssh::quote($target->rootBase);

        if ($target->rootDir !== null)
        {
            $command .= ' && { [ -d '. Ssh::quote($target->root) .' ] || mkdir '. Ssh::quote($target->root) .'; }';
        }

        $command .= ' && test -d '. Ssh::quote($target->root)
            .' && test -w '. Ssh::quote($target->root)
            .' && test -d '. Ssh::quote($target->webroot)
            .' && test -w '. Ssh::quote($target->webroot)
            .' && { [ ! -d '. Ssh::quote($storage) .' ] || test -w '. Ssh::quote($storage) .'; }'
            .' && { [ ! -d '. Ssh::quote($upload) .' ] || test -w '. Ssh::quote($upload) .'; }';

        $this->ssh->must($target, $command);

        $this->out('ssh ok, root and webroot are writable');

        $this->warnAboutEntrypoint();

        $hasRsync = $this->ssh->must(
            $target,
            'command -v rsync >/dev/null 2>&1 && echo yes || echo no',
        )->output() === 'yes';

        $this->out('transport: '. ($hasRsync ? 'rsync' : 'tar (fallback)'));

        $this->checkEnv();
        $this->checkRequirements();

        return $hasRsync;
    }


    public function assertEnvExists(): void
    {
        if ($this->localEnvExists) {
            return;
        }

        $local = Paths::override($this->projectRoot, $this->target->name, 'application') .'/env.json';

        throw new RuntimeException(
            "missing env for '{$this->target->name}': add {$local}",
        );
    }


    private function checkEnv(): void
    {
        $local = Paths::override($this->projectRoot, $this->target->name, 'application') .'/env.json';

        $this->localEnvExists = is_file($local);

        if ( ! $this->localEnvExists) {
            $this->out(
                "warning: no env override for '{$this->target->name}'; add {$local}",
            );
        }
    }


    private function checkRequirements(): void
    {
        $current = $this->remote->readCurrent();

        if (($current['status'] ?? null) === 'redirected') {
            $this->out('requirements: skipped for redirected target');

            return;
        }

        $requirements = ComposerRequirements::fromFile($this->projectRoot .'/composer.json');
        $probe = (new WebProbe())->inspect($this->target, $this->shell);
        $requirements->assert($probe);

        $this->out('requirements ok: PHP '. $probe['php']);
    }


    private function warnAboutEntrypoint(): void
    {
        if (rtrim(dirname($this->target->webroot), '/') === rtrim($this->target->root, '/'))
        {
            return;
        }

        $override = Paths::override($this->projectRoot, $this->target->name, 'public') .'/index.php';

        if ( ! is_file($override))
        {
            $this->out(
                'warning: webroot is not directly under root; add an index.php entrypoint at '
                .'.deploy/override/'. $this->target->name .'/public/index.php',
            );
        }
    }


    private function out(string $message): void
    {
        $this->output->line('['. $this->target->name .'] '. $message);
    }
}
