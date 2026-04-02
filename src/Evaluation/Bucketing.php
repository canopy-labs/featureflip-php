<?php

declare(strict_types=1);

namespace Featureflip\Evaluation;

final class Bucketing
{
    /**
     * Returns a bucket value 0-99 for the given salt and value.
     * Uses MD5, first 4 bytes as little-endian uint32, modulo 100.
     */
    public static function bucket(string $salt, string $value): int
    {
        $hash = md5($salt . ':' . $value, true);
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('V', substr($hash, 0, 4));
        return $unpacked[1] % 100;
    }
}
