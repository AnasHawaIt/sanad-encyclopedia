<?php

namespace App\Enums;

enum ReviewAction: string
{
    case Submit = 'submit';
    case SendBack = 'send_back';
    case Approve = 'approve';
    case Reject = 'reject';
    case Publish = 'publish';
    case Unpublish = 'unpublish';

    public function label(): string
    {
        return match ($this) {
            self::Submit => 'إرسال للمراجعة',
            self::SendBack => 'إعادة للاستكمال',
            self::Approve => 'اعتماد',
            self::Reject => 'رفض',
            self::Publish => 'نشر',
            self::Unpublish => 'إلغاء النشر',
        };
    }
}
