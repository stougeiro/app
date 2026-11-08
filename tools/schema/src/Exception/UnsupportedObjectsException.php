<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Exception;

use RuntimeException;


final class UnsupportedObjectsException extends RuntimeException
{
    /**
     * @param list<string> $objects
     */
    public static function fromObjects(array $objects): self
    {
        return new self(
            'database contains unsupported objects: '. implode(', ', $objects)
            .'. Schema manages structure only. Move triggers, procedures and functions to the application.',
        );
    }
}
