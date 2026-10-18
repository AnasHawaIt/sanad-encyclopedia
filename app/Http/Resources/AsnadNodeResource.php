<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\InternalFields;
use App\Models\AsnadNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * عنصر في السند. العلَم يظهر فقط إن كان مرئيًا للمستخدم (يُحمَّل بقيد الرؤية في المتحكم)؛
 * لا يخرج معرّف علَم غير مرئي ولا اسمه من سجل العلَم. أما نص السند الأصلي ومقطع العنصر فهما نص السند نفسه.
 *
 * @mixin AsnadNode
 */
class AsnadNodeResource extends JsonResource
{
    use InternalFields;

    public function toArray(Request $request): array
    {
        $person = $this->relationLoaded('person') ? $this->person : null;

        return [
            'id' => $this->id,
            'sequence_number' => $this->sequence_number,
            'person_id' => $person?->getKey(),
            'person' => $person ? new PersonBriefResource($person) : null,
            // عنصر مربوط بعلَم لا يراه المستخدم: يُعلَن وجود الربط دون كشف هويته
            'is_linked' => $this->person_id !== null,
            'unresolved_name' => $this->unresolved_name,
            'text_segment' => $this->text_segment,
            'chain_role' => $this->whenLoaded('role', fn () => $this->role ? ['id' => $this->role->id, 'name' => $this->role->name] : null),
            'transmission_mode' => $this->whenLoaded('transmissionMode', fn () => $this->transmissionMode ? ['id' => $this->transmissionMode->id, 'name' => $this->transmissionMode->name] : null),
            'notes' => $this->when($this->isInternal($request), $this->notes),
        ];
    }
}
