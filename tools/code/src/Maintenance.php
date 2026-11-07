<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code;


class Maintenance
{
    public function __construct(
        private readonly Ssh $ssh,
    ) {
    }


    public function enable(Target $target): void
    {
        $this->ssh->must(
            $target,
            'test -d '. Ssh::quote($target->webroot)
            .' && touch '. Ssh::quote($target->webroot .'/.maintenance'),
        );
    }


    public function disable(Target $target): void
    {
        $this->ssh->must(
            $target,
            'rm -f '. Ssh::quote($target->webroot .'/.maintenance'),
        );
    }


    public function status(Target $target): string
    {
        return $this->ssh->must(
            $target,
            'if [ -f '. Ssh::quote($target->webroot .'/.maintenance') .' ]; '
            .'then echo active; else echo inactive; fi',
        )->output();
    }
}
