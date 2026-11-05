<?php declare(strict_types=1);

namespace Tool\Cli;

use Tool\Contract\CommandInterface;
use Tool\Contract\Option;
use Tool\Contract\OutputInterface;


final class HelpRenderer
{
    /**
     * @param array<string, CommandInterface> $commands
     * @param list<Option> $globalOptions
     */
    public static function global(array $commands, array $globalOptions, OutputInterface $output, string $version): void
    {
        $output->line("Sourcerer {$version}");
        $output->line();
        $output->line('Usage:');
        $output->line('  sourcerer <command> [options]');
        $output->line();
        $output->line('Commands:');

        foreach ($commands as $command) {
            $output->line('  '. str_pad($command->name(), 28) . $command->description());
        }

        $output->line();
        $output->line('Options:');
        self::options($globalOptions, $output);
    }

    /** @param list<Option> $globalOptions */
    /**
     * @param array<string, CommandInterface> $commands
     * @param list<Option> $globalOptions
     */
    public static function group(string $group, array $commands, array $globalOptions, OutputInterface $output): void
    {
        $output->line($group);
        $output->line();
        $output->line('Commands:');

        foreach ($commands as $name => $command) {
            if ( ! str_starts_with($name, $group .' ')) {
                continue;
            }

            $output->line('  '. str_pad($command->name(), 28) . $command->description());
        }

        $output->line();
        $output->line('Options:');
        self::options($globalOptions, $output);
    }

    /** @param list<Option> $globalOptions */
    public static function command(CommandInterface $command, array $globalOptions, OutputInterface $output): void
    {
        $output->line($command->name());
        $output->line();
        $output->line($command->description());
        $output->line();
        $output->line('Options:');
        /** @var list<Option> $options */
        $options = [...$command->options(), ...$globalOptions];
        self::options($options, $output);
    }

    /** @param list<Option> $options */
    protected static function options(array $options, OutputInterface $output): void
    {
        foreach ($options as $option) {
            $label = '  --'. $option->name . ($option->takesValue ? '=<value>' : '');

            $output->line(str_pad($label, 32) . $option->description);
        }
    }
}
