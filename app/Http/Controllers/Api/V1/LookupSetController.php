<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChainRole;
use App\Models\IjazaType;
use App\Models\SanadType;
use App\Models\TransmissionMode;
use App\Services\Audit\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * القوائم القابلة للإدارة من لوحة التحكم: أنواع الإجازات وأنواع الأسانيد وأدوار العناصر وطرق التحمّل.
 * تُعطَّل ولا تُحذف، فلا تنكسر سجلات تشير إليها.
 */
class LookupSetController extends Controller
{
    /** @var array<string,array{0:class-string<Model>,1:string}> */
    private const SETS = [
        'ijaza-types' => [IjazaType::class, 'ijaza_types'],
        'sanad-types' => [SanadType::class, 'sanad_types'],
        'chain-roles' => [ChainRole::class, 'chain_roles'],
        'transmission-modes' => [TransmissionMode::class, 'transmission_modes'],
    ];

    public function __construct(private ActivityLogger $log) {}

    public function index(Request $request, string $set): JsonResponse
    {
        [$class] = $this->resolve($set);
        $query = $class::query()->ordered();
        if (! $request->user()->can('lookups.manage')) {
            $query->active();
        }

        return response()->json(['data' => $query->get()->map(fn (Model $m) => $this->present($m))->values()]);
    }

    public function store(Request $request, string $set): JsonResponse
    {
        [$class, $table] = $this->resolve($set);
        $data = $this->validated($request, $table, null, true);
        $this->assertUniqueName($class, $data['name'], null);

        $item = $class::create($data);
        $this->log->record("lookups.{$set}_created", $item, ['name' => $item->name], $request->user());

        return response()->json(['data' => $this->present($item->refresh())], 201);
    }

    public function update(Request $request, string $set, int $id): JsonResponse
    {
        [$class, $table] = $this->resolve($set);
        $item = $class::query()->findOrFail($id);
        $data = $this->validated($request, $table, $item, false);
        if (isset($data['name'])) {
            $this->assertUniqueName($class, $data['name'], $item->getKey());
        }

        $item->fill($data)->save();
        $changes = array_diff_key($item->getChanges(), ['updated_at' => 1]);
        if ($changes !== []) {
            $this->log->record("lookups.{$set}_updated", $item, $changes, $request->user());
        }

        return response()->json(['data' => $this->present($item->refresh())]);
    }

    /** @return array{0:class-string<Model>,1:string} */
    private function resolve(string $set): array
    {
        return self::SETS[$set] ?? abort(404);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, string $table, ?Model $item, bool $create): array
    {
        return $request->validate([
            'name' => $create ? ['required', 'string', 'max:255'] : ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
        ]);
    }

    private function assertUniqueName(string $class, string $name, ?int $ignore): void
    {
        $exists = $class::query()->where('name', $name)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => __('asnad.lookup_duplicate')]);
        }
    }

    /** @return array<string,mixed> */
    private function present(Model $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'description' => $m->description,
            'is_active' => $m->is_active,
            'sort_order' => $m->sort_order,
        ];
    }
}
