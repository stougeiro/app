<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema;

use RuntimeException;
use Sourcerer\Tool\Contract\CommandInterface;
use Sourcerer\Tool\Contract\ToolInterface;
use Sourcerer\Tool\Schema\Command\InitCommand;
use Sourcerer\Tool\Schema\Command\StatusCommand;
use Sourcerer\Tool\Support\FileSystem;


final class SchemaTool implements ToolInterface
{
    public const VERSION = '0.1.0';


    public function name(): string
    {
        return 'schema';
    }

    public function description(): string
    {
        return 'Structural database schema versioning tool';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /** @return array<string, CommandInterface> */
    public function commands(string $projectRoot): array
    {
        return [
            'schema init' => new InitCommand($projectRoot),
            'schema status' => new StatusCommand($projectRoot),
        ];
    }

    /** @return list<string> */
    public function install(string $projectRoot): array
    {
        $base = rtrim($projectRoot, '/') .'/.sourcerer/schema';
        $config = $base .'/config.json';
        $example = dirname(__DIR__) .'/schema.example.json';
        $lines = [];

        foreach ([$base, $base .'/ssh', $base .'/local/indexes', $base .'/local/targets', $base .'/local/tmp'] as $directory) {
            if (is_link($directory)) {
                throw new RuntimeException("refusing to use symlink: {$directory}");
            }

            if (is_dir($directory)) {
                $lines[] = 'exists '. $this->display($projectRoot, $directory);
                continue;
            }

            FileSystem::ensureDirectory($directory);
            $lines[] = 'created '. $this->display($projectRoot, $directory);
        }

        if (is_link($config)) {
            throw new RuntimeException("refusing to use symlink: {$config}");
        }

        if (is_file($config)) {
            $lines[] = 'exists '. $this->display($projectRoot, $config);

            return $lines;
        }

        FileSystem::copyFile($example, $config);
        FileSystem::chmod($config, 0600);
        $lines[] = 'created '. $this->display($projectRoot, $config);

        return $lines;
    }


    private function display(string $projectRoot, string $path): string
    {
        $root = rtrim($projectRoot, '/');

        if (str_starts_with($path, $root .'/')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }
}
