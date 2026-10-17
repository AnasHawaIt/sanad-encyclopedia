<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum NameVariantType: string
{
    use HasOptions;

    case Name = 'name';
    case Laqab = 'laqab';
    case Kunyah = 'kunyah';
    case Nisbah = 'nisbah';

    public function label(): string
    {
        return match ($this) {
            self::Name => 'اسم',
            self::Laqab => 'لقب',
            self::Kunyah => 'كنية',
            self::Nisbah => 'نسبة',
        };
    }
}
