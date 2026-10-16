<?php

namespace App\Services\Audit;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** يكتب في سجل التدقيق ويحجب أي حقل يشبه كلمة مرور أو رمزًا سريًّا. */
class ActivityLogger
{
    private const SECRET_PATTERN = '/password|passwd|token|secret|remember|api_key/i';

    public function record(string $event, ?Model $subject = null, array $changes = [], ?User $actor = null): ActivityLog
    {
        $actor ??= auth()->user();

        return ActivityLog::create([
            'user_id' => $actor?->getKey(),
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'changes' => $changes === [] ? null : $this->sanitize($changes),
            'ip_address' => request()?->ip(),
        ]);
    }

    public function sanitize(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_PATTERN, $key)) {
                $clean[$key] = '[hidden]';
            } elseif (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
