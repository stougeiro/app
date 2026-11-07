<?php declare(strict_types=1);

namespace Sourcerer\Tool\Manager;

use Sourcerer\Tool\Contract\CommandInterface;
use Sourcerer\Tool\Contract\Option;
use Sourcerer\Tool\Contract\OutputInterface;


final class HelpRenderer
{
    /**
     * @param array<string, CommandInterface> $commands
     * @param list<Option> $globalOptions
     * @param array<string, array{description: string, version: string}> $groups
     */
    public static function global(
        array $commands,
        array $globalOptions,
        OutputInterface $output,
        string $version,
        array $groups,
    ): void {
        $output->line("Sourcerer {$version}");
        $output->line();
        $output->line('Usage:');
        $output->line('  sourcerer <tool> [options]');
        $output->line('  sourcerer --help');
        $output->line('  sourcerer --version');
        $output->line();
        $output->line('Tools:');

        foreach ($groups as $name => $group) {
            $output->line(
                '  '. str_pad($name, 12) . str_pad($group['version'], 10) . $group['description'],
            );
        }

        $output->line();
        $output->line("Run 'sourcerer <tool> --help' for tool details.");
        $output->line();
        $output->line('Options:');
        self::options($globalOptions, $output);
    }


    /**
     * @param array<string, CommandInterface> $commands
     * @param list<Option> $globalOptions
     */
    public static function group(
        string $group,
        array $commands,
        array $globalOptions,
        OutputInterface $output,
        string $description,
        string $version,
    ): void {
        $output->line($group .' '. $version);
        $output->line($description);
        $output->line();
        $output->line('Usage:');
        $output->line("  sourcerer {$group} <command> [options]");
        $output->line("  sourcerer {$group} --help");
        $output->line("  sourcerer {$group} --version");
        $output->line();
        $output->line('Commands:');

        foreach ($commands as $name => $command) {
            if ( ! str_starts_with($name, $group .' ')) {
                continue;
            }

            $short = substr($name, strlen($group) + 1);
            $output->line('  '. $short);
            $output->line('    '. $command->description());
            $output->line('    Usage:');
            $output->line('      '. $command->usage());

            if ($command->options() !== []) {
                $output->line('    Options:');
                self::options($command->options(), $output, '      ');
            }

            $output->line();
        }

        $output->line('Options:');
        self::options($globalOptions, $output);
    }


    /**
     * @param list<Option> $options
     */
    protected static function options(array $options, OutputInterface $output, string $indent = '  '): void
    {
        foreach ($options as $option) {
            $label = $indent .'--'. $option->name;

            if ($option->takesValue) {
                $label .= '=<'. $option->valueName .'>';
            }

            $suffix = $option->required ? ' (required)' : '';

            if ( ! $option->required && $option->default !== null) {
                $suffix .= ' [default: '. (is_bool($option->default) ? ($option->default ? 'true' : 'false') : (string) $option->default) .']';
            }

            $output->line(str_pad($label, 34) . $option->description . $suffix);
        }
    }
}
