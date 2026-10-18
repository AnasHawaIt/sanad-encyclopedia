<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FindsRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Asnads\MoveRequest;
use App\Http\Requests\Asnads\NodeRequest;
use App\Http\Requests\Asnads\ReorderRequest;
use App\Http\Resources\AsnadResource;
use App\Models\Asnad;
use App\Services\Asnad\AsnadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** إدخال السند عنصرًا عنصرًا، وإعادة ترتيبه. تُعيد كل عملية السند كاملًا بأعداده الجديدة. */
class AsnadNodeController extends Controller
{
    use FindsRecords;

    public function __construct(private AsnadService $asnads) {}

    public function store(NodeRequest $request, int $asnad): JsonResponse
    {
        $model = $this->findVisible(Asnad::class, $asnad, $request);
        $this->asnads->addNode($request->user(), $model, $request->validated());

        return $this->respond($request, $model)->response()->setStatusCode(201);
    }

    public function update(NodeRequest $request, int $asnad, int $node): AsnadResource
    {
        $model = $this->findVisible(Asnad::class, $asnad, $request);
        $this->asnads->updateNode($request->user(), $model, $model->nodes()->findOrFail($node), $request->validated());

        return $this->respond($request, $model);
    }

    public function destroy(Request $request, int $asnad, int $node): AsnadResource
    {
        $model = $this->findVisible(Asnad::class, $asnad, $request);
        $this->asnads->removeNode($request->user(), $model, $model->nodes()->findOrFail($node));

        return $this->respond($request, $model);
    }

    public function reorder(ReorderRequest $request, int $asnad): AsnadResource
    {
        $model = $this->findVisible(Asnad::class, $asnad, $request);
        $this->asnads->reorder($request->user(), $model, $request->validated()['order']);

        return $this->respond($request, $model);
    }

    public function move(MoveRequest $request, int $asnad, int $node): AsnadResource
    {
        $model = $this->findVisible(Asnad::class, $asnad, $request);
        $this->asnads->moveNode($request->user(), $model, $model->nodes()->findOrFail($node), $request->validated()['position']);

        return $this->respond($request, $model);
    }

    private function respond(Request $request, Asnad $asnad): AsnadResource
    {
        return new AsnadResource(AsnadController::load($asnad->refresh(), $request->user()));
    }
}
