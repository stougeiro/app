<?php declare(strict_types=1);

namespace Sourcerer\Tool\Schema\Canonical;

use stdClass;


final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }


    public static function pretty(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) ."\n";
    }


    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $array = get_object_vars($value);

            if ($array === []) {
                return new stdClass();
            }

            $value = $array;
        }

        if ( ! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $result = [];

            foreach ($value as $item) {
                $result[] = self::normalize($item);
            }

            return $result;
        }

        $keys = array_keys($value);
        sort($keys, SORT_STRING);

        $result = [];

        foreach ($keys as $key) {
            $result[$key] = self::normalize($value[$key]);
        }

        return $result;
    }
}
