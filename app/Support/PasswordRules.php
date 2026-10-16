<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/** سياسة كلمة المرور الموحّدة. لا نستخدم uncompromised() لأنها تتصل بالإنترنت. */
final class PasswordRules
{
    public const MIN_LENGTH = 10;

    public static function default(): Password
    {
        return Password::min(self::MIN_LENGTH)->letters()->mixedCase()->numbers();
    }
}
