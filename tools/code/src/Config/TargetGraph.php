<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Config;

use RuntimeException;
use Sourcerer\Tool\Code\Target;


final class TargetGraph
{
    /**
     * @param array<string, Target> $targets
     */
    public static function assertUniqueAddresses(array $targets): void
    {
        $names = array_keys($targets);

        foreach ($names as $leftIndex => $leftName)
        {
            $left = $targets[$leftName];

            foreach (array_slice($names, $leftIndex + 1) as $rightName)
            {
                $right = $targets[$rightName];

                if ( ! self::sameServer($left, $right))
                {
                    continue;
                }

                if ($left->root === $right->root)
                {
                    throw new RuntimeException(
                        "config: targets '{$leftName}' and '{$rightName}' must not share root '{$left->root}' on the same server",
                    );
                }

                if ($left->webroot === $right->webroot)
                {
                    throw new RuntimeException(
                        "config: targets '{$leftName}' and '{$rightName}' must not share webroot '{$left->webroot}' on the same server",
                    );
                }

                if (self::normalizeUrl($left->url) === self::normalizeUrl($right->url))
                {
                    throw new RuntimeException(
                        "config: targets '{$leftName}' and '{$rightName}' must not share url '{$left->url}' on the same server",
                    );
                }
            }
        }
    }


    /**
     * @param array<string, Target> $targets
     * @return array<string, Target>
     */
    public static function withNestedWebroots(array $targets): array
    {
        $result = [];

        foreach ($targets as $name => $target)
        {
            $nested = [];

            foreach ($targets as $otherName => $other)
            {
                if ($name === $otherName || ! self::sameServer($target, $other))
                {
                    continue;
                }

                $prefix = $target->webroot .'/';

                if ( ! str_starts_with($other->webroot, $prefix))
                {
                    continue;
                }

                $relative = substr($other->webroot, strlen($prefix));

                if ($relative === '')
                {
                    continue;
                }

                if (str_contains($relative, '/'))
                {
                    throw new RuntimeException(
                        "config: target '{$otherName}' webroot must be a direct child of '{$name}' webroot or outside it",
                    );
                }

                $nested[] = $relative;
            }

            $result[$name] = new Target(
                $target->name,
                $target->sshHost,
                $target->sshUser,
                $target->sshPort,
                $target->sshIdentity,
                $target->webroot,
                $target->root,
                $target->rootBase,
                $target->rootDir,
                $target->url,
                $target->preserve,
                $target->backupKeep,
                array_values(array_unique($nested)),
                $target->releaseKeep,
                $target->eventKeep,
            );
        }

        return $result;
    }


    private static function sameServer(Target $left, Target $right): bool
    {
        return strtolower($left->sshHost) === strtolower($right->sshHost)
            && $left->sshPort === $right->sshPort;
    }


    private static function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if ( ! is_array($parts))
        {
            return rtrim($url, '/');
        }

        $scheme = strtolower(is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '');
        $host = strtolower(is_string($parts['host'] ?? null) ? $parts['host'] : '');
        $port = is_int($parts['port'] ?? null) ? ':'. $parts['port'] : '';
        $path = rtrim(is_string($parts['path'] ?? null) ? $parts['path'] : '', '/');

        return $scheme .'://'. $host . $port . $path;
    }
}
