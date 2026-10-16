<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    public function index(Request $request)
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'nullable', 'in:active,inactive,trashed'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()->with('roles')->orderBy('name');

        match ($request->input('status')) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            'trashed' => $query->onlyTrashed(),
            default => null,
        };

        if ($term = $request->input('q')) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }
        if ($role = $request->input('role')) {
            $query->role($role);
        }

        return UserResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles'));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->create($request->user(), $request->validated());

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        return new UserResource($this->users->update($request->user(), $user, $request->validated()));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->users->delete($request->user(), $user);

        return response()->json(null, 204);
    }

    public function restore(Request $request, int $user): UserResource
    {
        $model = User::onlyTrashed()->findOrFail($user);

        return new UserResource($this->users->restore($request->user(), $model));
    }
}
