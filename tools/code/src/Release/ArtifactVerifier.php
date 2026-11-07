<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Release;

use RuntimeException;
use Sourcerer\Tool\Code\Shell;
use Sourcerer\Tool\Code\Ssh;
use Sourcerer\Tool\Code\Target;


final class ArtifactVerifier
{
    public function hash(string $file): string
    {
        $hash = hash_file('sha256', $file);

        if ($hash === false) {
            throw new RuntimeException("could not hash artifact: {$file}");
        }

        return 'sha256:'. $hash;
    }


    /** @return array{available: bool, ok: bool} */
    public function verifyRemote(Target $target, Shell $shell, string $path, string $expected): array
    {
        $expected = str_starts_with($expected, 'sha256:') ? substr($expected, 7) : $expected;
        $quoted = Ssh::quote($path);
        $command = 'if command -v php >/dev/null 2>&1; then '
            .'php -r '. Ssh::quote('echo hash_file("sha256", $argv[1]);') .' -- '. $quoted .'; '
            .'elif command -v sha256sum >/dev/null 2>&1; then sha256sum '. $quoted .' | cut -d" " -f1; '
            .'elif command -v shasum >/dev/null 2>&1; then shasum -a 256 '. $quoted .' | cut -d" " -f1; '
            .'elif command -v openssl >/dev/null 2>&1; then openssl dgst -sha256 -r '. $quoted .' | cut -d" " -f1; '
            .'else echo __NO_TOOL__; fi';

        $result = (new Ssh($shell))->run($target, $command);
        $output = trim($result->stdout);

        if ($output === '__NO_TOOL__') {
            return ['available' => false, 'ok' => false];
        }

        if ( ! $result->ok() || $output === '') {
            return ['available' => true, 'ok' => false];
        }

        return ['available' => true, 'ok' => hash_equals($expected, strtok($output, " \n"))];
    }
}
