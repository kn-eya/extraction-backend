<?php

namespace App\Support;

class BelgiumGeography
{
    private const PROVINCE_TO_REGION = [
        'Anvers' => 'Flandre',
        'Brabant flamand' => 'Flandre',
        'Flandre-Occidentale' => 'Flandre',
        'Flandre-Orientale' => 'Flandre',
        'Limbourg' => 'Flandre',

        'Brabant wallon' => 'Wallonie',
        'Hainaut' => 'Wallonie',
        'Liege' => 'Wallonie',
        'Luxembourg' => 'Wallonie',
        'Namur' => 'Wallonie',

        'Bruxelles-Capitale' => 'Bruxelles-Capitale',
    ];

    public static function regionForProvince(?string $province): ?string
    {
        if (! $province) {
            return null;
        }

        return self::PROVINCE_TO_REGION[$province] ?? null;
    }

    public static function isRegion(string $value): bool
    {
        return in_array($value, array_unique(self::PROVINCE_TO_REGION), true);
    }
}
