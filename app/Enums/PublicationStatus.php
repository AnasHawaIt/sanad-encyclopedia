<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PublicationStatus: string
{
    use HasOptions;

    case Unpublished = 'unpublished';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Unpublished => 'غير منشور',
            self::Published => 'منشور',
        };
    }
}
