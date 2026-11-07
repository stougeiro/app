<?php declare(strict_types=1);

namespace Tool\Deploy\Requirements;

use RuntimeException;
use Tool\Deploy\Shell;
use Tool\Deploy\Ssh;
use Tool\Deploy\Target;


final class WebProbe
{
    /** @return array{php: string, extensions: list<string>} */
    public function inspect(Target $target, Shell $shell): array
    {
        $name = '_sr-check-'. bin2hex(random_bytes(6)) .'.php';
        $token = bin2hex(random_bytes(24));
        $path = rtrim($target->webroot, '/') .'/'. $name;
        $url = rtrim($target->url, '/') .'/'. $name .'?token='. rawurlencode($token);
        $ssh = new Ssh($shell);

        $contents = <<<PHP
<?php
if (!hash_equals('{$token}', (string) (\$_GET['token'] ?? ''))) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json');
echo json_encode([
    'php' => PHP_VERSION,
    'extensions' => get_loaded_extensions(),
]);
PHP;

        try {
            $ssh->must($target, 'cat > '. Ssh::quote($path), $contents);

            $response = $shell->run(['curl', '-sS', '--max-time', '20', $url]);

            if ( ! $response->ok()) {
                throw new RuntimeException('requirements: web probe request failed');
            }

            $data = json_decode($response->stdout, true);

            if ( ! is_array($data) || ! is_string($data['php'] ?? null) || ! is_array($data['extensions'] ?? null)) {
                throw new RuntimeException('requirements: invalid web probe response');
            }

            $extensions = [];

            foreach ($data['extensions'] as $extension) {
                if (is_string($extension)) {
                    $extensions[] = $extension;
                }
            }

            return ['php' => $data['php'], 'extensions' => $extensions];
        } finally {
            $ssh->run($target, 'rm -f '. Ssh::quote($path));
        }
    }
}
