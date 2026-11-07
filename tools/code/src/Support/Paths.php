<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Support;


final class Paths
{
    public static function override(string $projectRoot, string $targetName, string $tree): string
    {
        return rtrim($projectRoot, '/') .'/.deploy/override/'. $targetName .'/'. $tree;
    }
}
