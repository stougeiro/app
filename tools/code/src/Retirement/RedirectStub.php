<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Retirement;

use RuntimeException;


final class RedirectStub
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }


    /** @return array{index: string, htaccess: string} */
    public function render(string $url): array
    {
        $index = $this->read('index.php');
        $htaccess = $this->read('.htaccess');

        if ( ! str_contains($index, '%url%')) {
            throw new RuntimeException('deploy stub index.php must contain %url%');
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $url);

        return [
            'index' => str_replace('%url%', $escaped, $index),
            'htaccess' => $htaccess,
        ];
    }


    private function read(string $file): string
    {
        $custom = $this->projectRoot .'/.deploy/stub/'. $file;

        if (is_file($custom)) {
            return $this->contents($custom);
        }

        return $this->contents(dirname(__DIR__, 2) .'/resources/stub/'. $file);
    }

    private function contents(string $path): string
    {
        if ( ! is_file($path)) {
            throw new RuntimeException("deploy stub not found: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("could not read deploy stub: {$path}");
        }

        return $contents;
    }
}
