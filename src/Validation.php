<?php
declare(strict_types=1);

namespace AthMovil;

/** @internal */
final class Validation
{
    public static function nonBlank(#[\SensitiveParameter] string $value): string
    {
        if (trim($value) === '' || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException('Value must be nonblank and contain no control characters.');
        }
        return $value;
    }

    public static function phone(string $value): string
    {
        if (!preg_match('/^[0-9]{10}$/D', $value)) {
            throw new \InvalidArgumentException('Phone number must contain exactly ten digits.');
        }
        return $value;
    }

    public static function text(string $value, int $max): string
    {
        $length = preg_match_all('/./us', $value);
        if ($length === false || $length > $max) {
            throw new \InvalidArgumentException('Text must be valid UTF-8 and within the field character limit.');
        }
        return $value;
    }
}
