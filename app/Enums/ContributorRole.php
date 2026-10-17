<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ContributorRole: string
{
    use HasOptions;

    case Contributor = 'contributor';
    case Commentator = 'commentator';
    case Abridger = 'abridger';
    case Compiler = 'compiler';
    case Editor = 'editor';

    public function label(): string
    {
        return match ($this) {
            self::Contributor => 'مشارك',
            self::Commentator => 'شارح',
            self::Abridger => 'مختصِر',
            self::Compiler => 'جامع',
            self::Editor => 'محقق',
        };
    }
}
