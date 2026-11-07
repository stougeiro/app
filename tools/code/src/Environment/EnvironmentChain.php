<?php declare(strict_types=1);

namespace Tool\Deploy\Environment;

use Tool\Deploy\Config;


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
