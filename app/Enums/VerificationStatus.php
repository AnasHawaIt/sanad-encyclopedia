<?php

namespace App\Enums;

enum VerificationStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case NeedsRevision = 'needs_revision';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::InReview => 'قيد المراجعة',
            self::NeedsRevision => 'يحتاج إلى استكمال',
            self::Verified => 'موثّق',
            self::Rejected => 'مرفوض',
        };
    }
}
