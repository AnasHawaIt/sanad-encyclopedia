<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum SourceType: string
{
    use HasOptions;

    case Book = 'book';
    case Manuscript = 'manuscript';
    case Article = 'article';
    case Thesis = 'thesis';
    case Website = 'website';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Book => 'كتاب',
            self::Manuscript => 'مخطوط',
            self::Article => 'مقال أو بحث',
            self::Thesis => 'رسالة جامعية',
            self::Website => 'موقع إلكتروني',
            self::Other => 'أخرى',
        };
    }
}
