<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Event;

use Throwable;
use Sourcerer\Tool\Contract\OutputInterface;
use Sourcerer\Tool\Contract\Operator;
use Sourcerer\Tool\Code\Shell;
use Sourcerer\Tool\Code\Target;


final class EventWriter
{
    private readonly EventLog $log;


    public function __construct(
        private readonly Target $target,
        private readonly Operator $operator,
        private readonly OutputInterface $output,
        string $projectRoot,
        Shell $shell,
    ) {
        $this->log = new EventLog($projectRoot, $target, $shell);
    }


    /** @param array<string, mixed> $context */
    public function write(
        string $action,
        string $releaseId,
        ?string $from = null,
        ?string $to = null,
        ?string $backup = null,
        array $context = [],
    ): void {
        try {
            $this->log->write(new DeployEvent(
                $action,
                $releaseId,
                $this->target->name,
                $from,
                $to,
                gmdate('c'),
                $this->operator->toArray(),
                $backup,
                context: $context,
            ));
        } catch (Throwable $exception) {
            $this->output->line(
                '['. $this->target->name .'] warning: could not write deploy event: '. $exception->getMessage(),
            );
        }
    }


    public function prune(int $keep): void
    {
        $this->log->prune($keep);
    }
}
