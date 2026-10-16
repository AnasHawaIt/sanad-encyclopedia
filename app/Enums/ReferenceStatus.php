<?php

namespace App\Enums;

enum ReferenceStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'غير محقق',
            self::Verified => 'محقق',
            self::Rejected => 'مرفوض',
        };
    }
}
