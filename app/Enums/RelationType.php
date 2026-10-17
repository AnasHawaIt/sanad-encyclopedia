<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum RelationType: string
{
    use HasOptions;

    case Unspecified = 'unspecified';
    case Reading = 'reading';
    case Hearing = 'hearing';
    case Ijaza = 'ijaza';
    case Companionship = 'companionship';

    public function label(): string
    {
        return match ($this) {
            self::Unspecified => 'غير محدد',
            self::Reading => 'قراءة',
            self::Hearing => 'سماع',
            self::Ijaza => 'إجازة',
            self::Companionship => 'ملازمة',
        };
    }
}
