<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\State;

use JsonException;
use RuntimeException;


final class LocalTargetState
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly string $targetName,
    ) {
    }


    /** @param array<string, mixed> $data */
    public function write(array $data): void
    {
        $directory = rtrim($this->projectRoot, '/') .'/.deploy/targets';

        if ( ! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("could not create local target directory: {$directory}");
        }

        try {
            $json = json_encode(
                ['target' => $this->targetName, 'updatedAt' => gmdate('c')] + $data,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('could not encode local target metadata: '. $exception->getMessage(), 0, $exception);
        }

        if (file_put_contents($directory .'/'. $this->targetName .'.json', $json ."\n") === false) {
            throw new RuntimeException('could not write local target metadata');
        }
    }
}
