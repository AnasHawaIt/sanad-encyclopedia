<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ClaimType: string
{
    use HasOptions;

    case Birth = 'birth';
    case Death = 'death';

    public function label(): string
    {
        return match ($this) {
            self::Birth => 'ولادة',
            self::Death => 'وفاة',
        };
    }
}
