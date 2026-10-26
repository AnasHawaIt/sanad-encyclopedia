<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Audit\ActivityLogger;
use App\Services\Exports\ExportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** تصدير القراءة فقط: يبني ملفًا من السجلات المرئية للمستخدم ويسجّل العملية في التدقيق دون محتواها. */
class ExportController extends Controller
{
    public function __construct(private ExportService $exports, private ActivityLogger $audit) {}

    public function asnads(Request $request): Response
    {
        $data = $request->validate([
            'format' => ['sometimes', 'in:json,csv,csv_nodes,txt'],
            'ids' => ['sometimes', 'array', 'max:'.ExportService::limit('asnads')],
            'ids.*' => ['integer', 'distinct'],
            'book_id' => ['sometimes', 'nullable', 'integer'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $format = $data['format'] ?? 'json';
        $result = $this->exports->asnads($request->user(), $data['ids'] ?? null, $data['book_id'] ?? null, $data['q'] ?? null);
        $items = $result['items'];

        $body = match ($format) {
            'csv' => $this->exports->asnadsCsv($items),
            'csv_nodes' => $this->exports->asnadNodesCsv($items),
            'txt' => $this->exports->asnadsText($items),
            default => $this->json($this->exports->asnadsJson($items, $request), $result),
        };

        return $this->deliver($request, 'asnads', $format, $body, $result, array_diff_key($data, ['format' => 1]));
    }

    public function persons(Request $request): Response
    {
        $data = $request->validate([
            'format' => ['sometimes', 'in:json,csv'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'death_from' => ['sometimes', 'nullable', 'integer', 'between:1,1700'],
            'death_to' => ['sometimes', 'nullable', 'integer', 'between:1,1700'],
        ]);
        $format = $data['format'] ?? 'json';
        $result = $this->exports->persons($request->user(), $data['q'] ?? null, $data['death_from'] ?? null, $data['death_to'] ?? null);

        $body = $format === 'csv'
            ? $this->exports->personsCsv($result['items'])
            : $this->json($this->exports->personsJson($result['items'], $request), $result);

        return $this->deliver($request, 'persons', $format, $body, $result, array_diff_key($data, ['format' => 1]));
    }

    public function ijazas(Request $request): Response
    {
        $data = $request->validate([
            'format' => ['sometimes', 'in:json,csv'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'grantor_person_id' => ['sometimes', 'nullable', 'integer'],
            'recipient_person_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $format = $data['format'] ?? 'json';
        $result = $this->exports->ijazas($request->user(), $data['q'] ?? null, $data['grantor_person_id'] ?? null, $data['recipient_person_id'] ?? null);

        $body = $format === 'csv'
            ? $this->exports->ijazasCsv($result['items'])
            : $this->json($this->exports->ijazasJson($result['items'], $request), $result);

        return $this->deliver($request, 'ijazas', $format, $body, $result, array_diff_key($data, ['format' => 1]));
    }

    private function json(array $rows, array $result): string
    {
        return json_encode([
            'meta' => ['count' => count($rows), 'total' => $result['total'], 'truncated' => $result['truncated'], 'exported_at' => now()->toIso8601String()],
            'data' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    private function deliver(Request $request, string $kind, string $format, string $body, array $result, array $filters): Response
    {
        $count = $result['items']->count();
        $this->audit->record('exports.created', null, ['kind' => $kind, 'format' => $format, 'count' => $count, 'truncated' => $result['truncated'], 'filters' => $filters]);

        $type = str_starts_with($format, 'csv') ? 'text/csv; charset=UTF-8' : ($format === 'txt' ? 'text/plain; charset=UTF-8' : 'application/json; charset=UTF-8');
        $ext = str_starts_with($format, 'csv') ? 'csv' : $format;
        $suffix = $format === 'csv_nodes' ? '-halaqat' : '';

        return response($body, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="sanad-'.$kind.$suffix.'-'.now()->format('Ymd-His').'.'.$ext.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
            'X-Export-Count' => (string) $count,
            'X-Export-Total' => (string) $result['total'],
            'X-Export-Truncated' => $result['truncated'] ? '1' : '0',
        ]);
    }
}
