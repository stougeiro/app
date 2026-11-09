<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Command;

use Sourcerer\Tool\Contract\CommandInterface;
use Sourcerer\Tool\Contract\InputInterface;
use Sourcerer\Tool\Contract\Option;
use Sourcerer\Tool\Schema\Config;
use Sourcerer\Tool\Schema\Config\DatabaseConfig;


abstract class CommandAbstract implements CommandInterface
{
    private ?Config $config = null;


    public function __construct(
        protected string $projectRoot,
    ) {
    }


    public function usage(): string
    {
        $parts = ['sourcerer', $this->name()];

        foreach ($this->options() as $option) {
            $label = '--'. $option->name;

            if ($option->takesValue) {
                $label .= '=<'. $option->valueName .'>';
            }

            $parts[] = $option->required ? $label : '['. $label .']';
        }

        return implode(' ', $parts);
    }


    /** @return list<Option> */
    public function options(): array
    {
        return $this->commandOptions();
    }


    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [];
    }


    protected function config(): Config
    {
        return $this->config ??= Config::load($this->projectRoot);
    }


    protected function database(InputInterface $input): DatabaseConfig
    {
        $name = $input->string('database') ?? 'default';

        return $this->config()->database($name);
    }
}
