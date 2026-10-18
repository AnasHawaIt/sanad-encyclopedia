<?php

namespace App\Services\Ijazas;

use App\Models\Ijaza;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Concerns\SyncsChildren;
use App\Services\Persons\PersonAccess;
use App\Services\Search\ArabicNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * النص الأصلي للإجازة لا يُعدَّل بعد حفظه؛ التصحيح في revised_text، والمطبَّع للبحث في text_search
 * (من النصين معًا). السنة الهجرية والتاريخ الميلادي حقلان مستقلان ولا يُحوَّل أحدهما إلى الآخر.
 */
class IjazaService
{
    use SyncsChildren;

    private const FIELDS = [
        'ijaza_number', 'ijaza_type_id', 'grantor_person_id', 'recipient_person_id', 'ijaza_date_text',
        'ijaza_year_h', 'ijaza_date_gregorian', 'ijaza_place', 'revised_text', 'summary', 'notes',
    ];

    public function __construct(
        private ActivityLogger $log,
        private ArabicNormalizer $normalizer,
        private PersonAccess $access,
    ) {}

    public function create(User $actor, array $data): Ijaza
    {
        $this->assertPeopleUsable($actor, $data);

        return DB::transaction(function () use ($actor, $data) {
            $ijaza = new Ijaza(array_intersect_key($data, array_flip(self::FIELDS)));
            $ijaza->original_text = $data['original_text'] ?? null;
            $this->fillSearch($ijaza);
            $ijaza->created_by = $actor->getKey();
            $ijaza->updated_by = $actor->getKey();
            $ijaza->save();

            $this->log->record('ijazas.created', $ijaza, ['ijaza_number' => $ijaza->ijaza_number], $actor);

            return $this->loaded($ijaza->refresh());
        });
    }

    public function update(User $actor, Ijaza $ijaza, array $data): Ijaza
    {
        $this->assertPeopleUsable($actor, $data);

        if (array_key_exists('original_text', $data) && filled($ijaza->original_text) && $data['original_text'] !== $ijaza->original_text) {
            throw ValidationException::withMessages(['original_text' => __('asnad.original_locked')]);
        }

        return DB::transaction(function () use ($actor, $ijaza, $data) {
            $ijaza->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            if (array_key_exists('original_text', $data) && blank($ijaza->original_text)) {
                $ijaza->original_text = $data['original_text'];
            }
            $this->fillSearch($ijaza);
            $ijaza->updated_by = $actor->getKey();
            $ijaza->save();

            $changes = $this->diff($ijaza, ['text_search']);
            if ($changes !== []) {
                $this->log->record('ijazas.updated', $ijaza, $changes, $actor);
            }

            return $this->loaded($ijaza->refresh());
        });
    }

    /** الإجازة هي المالكة لروابطها (كتب وأسانيد)، فتُؤرشف دون فكّها؛ تعود بها عند الاستعادة. */
    public function archive(User $actor, Ijaza $ijaza): void
    {
        DB::transaction(function () use ($actor, $ijaza) {
            $ijaza->delete();
            $this->log->record('ijazas.archived', $ijaza, ['ijaza_number' => $ijaza->ijaza_number], $actor);
        });
    }

    public function restore(User $actor, Ijaza $ijaza): Ijaza
    {
        return DB::transaction(function () use ($actor, $ijaza) {
            $ijaza->restore();
            $this->log->record('ijazas.restored', $ijaza, ['ijaza_number' => $ijaza->ijaza_number], $actor);

            return $this->loaded($ijaza->refresh());
        });
    }

    /**
     * تحذيرات علمية لا تمنع الحفظ (قرار ق2): الواقع يشمل إجازات مربوطة بكتب دون سند معروف.
     *
     * @return list<array{code:string,message:string}>
     */
    public function warnings(Ijaza $ijaza): array
    {
        $warnings = [];
        $books = DB::table('ijaza_book')->where('ijaza_id', $ijaza->getKey())->count();
        $asnads = DB::table('ijaza_asnad')->where('ijaza_id', $ijaza->getKey())->count();
        if ($books > 0 && $asnads === 0) {
            $warnings[] = ['code' => 'books_without_asnad', 'message' => __('asnad.warnings.books_without_asnad')];
        }

        return $warnings;
    }

    private function fillSearch(Ijaza $ijaza): void
    {
        $ijaza->text_search = $this->normalizer->normalize(trim(($ijaza->original_text ?? '').' '.($ijaza->revised_text ?? '')));
    }

    private function assertPeopleUsable(User $actor, array $data): void
    {
        $this->access->assertUsable($actor, [$data['grantor_person_id'] ?? null], 'grantor_person_id');
        $this->access->assertUsable($actor, [$data['recipient_person_id'] ?? null], 'recipient_person_id');
    }

    private function loaded(Ijaza $ijaza): Ijaza
    {
        return $ijaza->load(['type']);
    }
}
