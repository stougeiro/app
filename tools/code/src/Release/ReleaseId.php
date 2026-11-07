<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Release;

use DateTimeImmutable;
use DateTimeZone;


final class ReleaseId
{
    public static function fromHash(string $contentHash, ?DateTimeImmutable $at = null): string
    {
        $hash = str_starts_with($contentHash, 'sha256:')
            ? substr($contentHash, 7)
            : $contentHash;

        $at ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return substr($hash, 0, 12) .'-'. $at->format('Ymd\THis');
    }
}
