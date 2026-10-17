<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Certainty: string
{
    use HasOptions;

    case Certain = 'certain';
    case Approximate = 'approximate';
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Certain => 'مؤكد',
            self::Approximate => 'تقريبي',
            self::Disputed => 'مختلف فيه',
        };
    }
}
