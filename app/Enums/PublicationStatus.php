<?php

namespace App\Enums;

enum PublicationStatus: string
{
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
