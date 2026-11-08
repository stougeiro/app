<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Canonical;

use Sourcerer\Tool\Schema\Domain\SchemaState;


final class SchemaHasher
{
    public function hash(SchemaState $state): string
    {
        return $this->hashArray($state->toArray());
    }


    /** @param array<string, mixed> $data */
    public function hashArray(array $data): string
    {
        return 'sha256:'. hash('sha256', CanonicalJson::encode($data));
    }
}
