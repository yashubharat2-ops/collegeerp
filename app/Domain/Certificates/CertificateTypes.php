<?php

namespace App\Domain\Certificates;

/** Grouped, extensible certificate catalogue. Add later groups here only when approved. */
final class CertificateTypes
{
    public const GROUP_1 = [
        'tc' => 'Transfer Certificate (TC)',
        'bonafide' => 'Bonafide Certificate',
        'character' => 'Character Certificate',
    ];

    public static function label(string $type): string
    {
        return self::GROUP_1[$type] ?? throw new \InvalidArgumentException('Unsupported certificate type.');
    }

    public static function contains(?string $type): bool
    {
        return $type !== null && array_key_exists($type, self::GROUP_1);
    }
}
