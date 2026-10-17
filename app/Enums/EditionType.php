<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum EditionType: string
{
    use HasOptions;

    case Printed = 'printed';
    case Manuscript = 'manuscript';

    public function label(): string
    {
        return match ($this) {
            self::Printed => 'مطبوع',
            self::Manuscript => 'مخطوط',
        };
    }
}
