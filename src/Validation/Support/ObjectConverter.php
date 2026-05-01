<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Support;

use stdClass;

use function array_is_list;
use function is_array;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class ObjectConverter
{
    /**
     * Recursively convert PHP arrays to stdClass objects, matching the
     * behaviour of json_decode(json_encode($data)) without the intermediate
     * JSON string allocation.
     *
     * Associative arrays (including those with numeric string keys like "200")
     * become stdClass. Lists and empty arrays remain arrays. Non-array values
     * pass through unchanged.
     */
    public static function convert(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($value === [] || array_is_list($value)) {
            /** @var list<mixed> $value */
            foreach ($value as $i => $item) {
                $value[$i] = self::convert($item);
            }

            return $value;
        }

        $object = new stdClass();
        foreach ($value as $key => $item) {
            $object->{$key} = self::convert($item);
        }

        return $object;
    }
}
