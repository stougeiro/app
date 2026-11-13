<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code;

use RuntimeException;


final class SshAuth
{
    public const TYPE_KEY = 'key';

    public const TYPE_PASS = 'pass';


    public function __construct(
        public readonly string $type,
        public readonly string $file,
    ) {
    }


    public function isKey(): bool
    {
        return $this->type === self::TYPE_KEY;
    }


    public function isPassword(): bool
    {
        return $this->type === self::TYPE_PASS;
    }


    public function filePath(): string
    {
        if ( ! str_starts_with($this->file, '~/')) {
            return $this->file;
        }

        $home = getenv('HOME');

        if ($home === false || $home === '') {
            throw new RuntimeException('HOME is not set to expand '. $this->file);
        }

        return rtrim($home, '/') .'/'. substr($this->file, 2);
    }


    public function password(): string
    {
        $file = $this->filePath();

        if ( ! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException('ssh auth password file is not readable: '. $file);
        }

        $password = file_get_contents($file);

        if ($password === false) {
            throw new RuntimeException('ssh auth password file could not be read: '. $file);
        }

        if ($password === '') {
            throw new RuntimeException('ssh auth password file is empty: '. $file);
        }

        return $password;
    }


    /** @return list<string> */
    public function prefix(string $binary): array
    {
        return $this->isPassword() ? ['sshpass', '-e', $binary] : [$binary];
    }


    /** @return list<string> */
    public function options(): array
    {
        if ($this->isPassword()) {
            return [
                '-o', 'BatchMode=no',
                '-o', 'StrictHostKeyChecking=accept-new',
                '-o', 'PreferredAuthentications=password',
                '-o', 'PubkeyAuthentication=no',
                '-o', 'NumberOfPasswordPrompts=1',
            ];
        }

        return ['-o', 'BatchMode=yes', '-i', $this->filePath()];
    }


    /** @return array<string, string> */
    public function env(): array
    {
        return $this->isPassword() ? ['SSHPASS' => $this->password()] : [];
    }
}
