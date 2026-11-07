<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Environment;

use Sourcerer\Tool\Code\Config;


final class EnvironmentChain
{
    /**
     * @param list<string> $available
     */
    public static function next(string $current, array $available): ?string
    {
        $started = false;

        foreach (Config::PROMOTION as $environment) {
            if ($environment === $current) {
                $started = true;
                continue;
            }

            if ($started && in_array($environment, $available, true)) {
                return $environment;
            }
        }

        return null;
    }
}
