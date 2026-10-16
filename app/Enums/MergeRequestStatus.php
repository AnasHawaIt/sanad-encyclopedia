<?php

namespace App\Enums;

enum MergeRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Executed = 'executed';
    case Reverted = 'reverted';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار القرار',
            self::Approved => 'موافَق عليه',
            self::Rejected => 'مرفوض',
            self::Executed => 'منفَّذ',
            self::Reverted => 'تم التراجع عنه',
        };
    }
}
