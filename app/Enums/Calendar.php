<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Calendar: string
{
    use HasOptions;

    case Hijri = 'hijri';
    case Gregorian = 'gregorian';

    public function label(): string
    {
        return match ($this) {
            self::Hijri => 'هجري',
            self::Gregorian => 'ميلادي',
        };
    }
}
