<?php declare(strict_types=1);

namespace Tool\Cli;

use Tool\Contract\OutputInterface;


final class Prompt
{
    /** @var resource */
    protected $stdin;


    /**
     * @param resource|null $stdin
     */
    public function __construct(
        protected OutputInterface $output,
        protected bool $interactive = true,
        $stdin = null,
    ) {
        $this->stdin = $stdin ?? STDIN;
    }


    public function confirm(string $question, bool $default = false): bool
    {
        if ( ! $this->interactive) {
            return false;
        }

        $suffix = $default ? '[Y/n]' : '[y/N]';
        $this->output->line("{$question} {$suffix} ");

        $answer = is_resource($this->stdin) ? fgets($this->stdin) : false;

        if ($answer === false || trim($answer) === '') {
            return $default;
        }

        return in_array(strtolower(trim($answer)), ['y', 'yes', 's', 'sim'], true);
    }
}
