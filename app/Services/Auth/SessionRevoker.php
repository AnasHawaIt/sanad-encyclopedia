<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** يُسقط كل جلسات المستخدم ورموزه (عند التعطيل أو الحذف أو تغيير كلمة المرور). */
class SessionRevoker
{
    public function revoke(User $user, ?string $exceptSessionId = null): void
    {
        $user->tokens()->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
                ->delete();
        }
    }
}
