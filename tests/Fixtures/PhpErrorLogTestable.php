<?php declare(strict_types=1);

namespace Tests\Fixtures;

use App\Core\Log\PhpErrorLog;


final class PhpErrorLogTestable extends PhpErrorLog
{
    /** @var array<string, bool> */
    public static array $functions = [];

    /** @var array<string, string|false> */
    public static array $ini = [];

    /** @var array<string, string|false> */
    public static array $iniSetResults = [];

    /** @var list<array{name: string, value: string}> */
    public static array $iniSetCalls = [];

    /** @var array<string, bool> */
    public static array $directories = [];

    /** @var array<string, bool> */
    public static array $files = [];

    /** @var array<string, bool> */
    public static array $writable = [];

    public static bool $defaultDirectory = true;

    public static bool $defaultFile = false;

    public static bool $defaultWritable = true;


    public static function reset(): void
    {
        self::$functions = [
            'ini_set' => true,
            'ini_get' => true,
        ];
        self::$ini = [
            'disable_functions' => '',
            'error_log' => '',
        ];
        self::$iniSetResults = [];
        self::$iniSetCalls = [];
        self::$directories = [];
        self::$files = [];
        self::$writable = [];
        self::$defaultDirectory = true;
        self::$defaultFile = false;
        self::$defaultWritable = true;
    }


    public static function disableFunction(string $name): void
    {
        self::$functions[$name] = false;
    }

    public static function allowWritable(string $path): void
    {
        self::$writable[$path] = true;
    }

    public static function denyWritable(string $path): void
    {
        self::$writable[$path] = false;
    }

    public static function allowFile(string $path): void
    {
        self::$files[$path] = true;
    }

    public static function denyDirectory(string $path): void
    {
        self::$directories[$path] = false;
    }

    public static function setIni(string $name, string|false $value): void
    {
        self::$ini[$name] = $value;
    }

    public static function failIniSet(string $name): void
    {
        self::$iniSetResults[$name] = false;
    }

    public static function iniSetReturns(string $name, string|false $result): void
    {
        self::$iniSetResults[$name] = $result;
    }


    protected static function doFunctionExists(string $name): bool
    {
        return self::$functions[$name] ?? parent::doFunctionExists($name);
    }

    protected static function doIniGet(string $name): string|false
    {
        return array_key_exists($name, self::$ini) ? self::$ini[$name] : parent::doIniGet($name);
    }

    protected static function doIniSet(string $name, string $value): string|false
    {
        self::$iniSetCalls[] = ['name' => $name, 'value' => $value];

        $previous = array_key_exists($name, self::$ini) ? self::$ini[$name] : parent::doIniGet($name);

        if (array_key_exists($name, self::$iniSetResults)) {
            return self::$iniSetResults[$name];
        }

        self::$ini[$name] = $value;

        return is_string($previous) ? $previous : '';
    }

    protected static function doIsDir(string $path): bool
    {
        return self::$directories[$path] ?? self::$defaultDirectory;
    }

    protected static function doIsFile(string $path): bool
    {
        return self::$files[$path] ?? self::$defaultFile;
    }

    protected static function doIsWritable(string $path): bool
    {
        return self::$writable[$path] ?? self::$defaultWritable;
    }
}
