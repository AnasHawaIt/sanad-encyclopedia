<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** عرض سجل التدقيق: قراءة فقط، لا مسار تعديل ولا حذف. */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'event' => ['sometimes', 'nullable', 'string', 'max:64'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'subject_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'subject_id' => ['sometimes', 'nullable', 'integer'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ActivityLog::query()->with('user:id,name')->orderByDesc('id');
        if (! empty($data['event'])) {
            // «persons.» تعني كل أحداث الأعلام؛ غير ذلك مطابقة تامة
            str_ends_with($data['event'], '.')
                ? $query->where('event', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['event']).'%')
                : $query->where('event', $data['event']);
        }
        foreach (['user_id', 'subject_type', 'subject_id'] as $filter) {
            if (! empty($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }
        if (! empty($data['from'])) {
            $query->where('created_at', '>=', $data['from'].' 00:00:00');
        }
        if (! empty($data['to'])) {
            $query->where('created_at', '<=', $data['to'].' 23:59:59');
        }

        $page = $query->paginate((int) ($data['per_page'] ?? 50));
        $page->getCollection()->transform(fn (ActivityLog $l) => [
            'id' => $l->id, 'event' => $l->event,
            'user' => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name] : null,
            'subject_type' => $l->subject_type, 'subject_id' => $l->subject_id,
            'changes' => $l->changes, 'ip_address' => $l->ip_address, 'created_at' => $l->created_at?->toIso8601String(),
        ]);

        return JsonResource::collection($page)->response();
    }

    public function events(): JsonResponse
    {
        return response()->json(['data' => ActivityLog::query()->select('event')->distinct()->orderBy('event')->pluck('event')]);
    }
}
