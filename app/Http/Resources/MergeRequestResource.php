<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\InternalFields;
use App\Models\PersonMergeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * طلب دمج. اسم علَم لا يراه المستخدم لا يُعرض (يُمرِّر المتحكم أرقام الأعلام المرئية في سمة الطلب visible_person_ids).
 *
 * @mixin PersonMergeRequest
 */
class MergeRequestResource extends JsonResource
{
    use InternalFields;

    /** @var array<string,mixed>|null */
    public ?array $preview = null;

    public function withPreview(array $preview): static
    {
        $this->preview = $preview;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $visible = $request->attributes->get('visible_person_ids', []);
        $person = fn ($p) => $p && in_array($p->getKey(), $visible, true) ? new PersonBriefResource($p) : null;
        $snapshot = $this->snapshot ?? [];

        return [
            'id' => $this->id,
            'status' => $this->status($this->status),
            'reason' => $this->reason,
            'primary_person_id' => in_array($this->primary_person_id, $visible, true) ? $this->primary_person_id : null,
            'secondary_person_id' => in_array($this->secondary_person_id, $visible, true) ? $this->secondary_person_id : null,
            'primary' => $this->whenLoaded('primaryPerson', fn () => $person($this->primaryPerson)),
            'secondary' => $this->whenLoaded('secondaryPerson', fn () => $person($this->secondaryPerson)),
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester ? ['id' => $this->requester->id, 'name' => $this->requester->name] : null),
            'decided_by' => $this->whenLoaded('decider', fn () => $this->decider ? ['id' => $this->decider->id, 'name' => $this->decider->name] : null),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_notes' => $this->decision_notes,
            'executed_at' => $this->executed_at?->toIso8601String(),
            'reverted_at' => $this->reverted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'result' => $this->when($snapshot !== [], fn () => [
                'counts' => $snapshot['counts'] ?? [],
                'skipped' => $snapshot['skipped'] ?? [],
                'added_name_variants' => count($snapshot['created_variant_ids'] ?? []),
                'revert' => isset($snapshot['revert']) ? [
                    'restored_rows' => $snapshot['revert']['restored_rows'] ?? 0,
                    'conflicts' => $snapshot['revert']['conflicts'] ?? [],
                    'notes' => $snapshot['revert']['notes'] ?? null,
                ] : null,
            ]),
            'preview' => $this->when($this->preview !== null, fn () => $this->preview),
        ];
    }
}
