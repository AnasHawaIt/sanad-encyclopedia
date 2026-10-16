<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Services\Access\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(private RoleService $roles) {}

    public function index()
    {
        return RoleResource::collection($this->roles->withUserCount(Role::query())->with('permissions')->orderBy('id')->get());
    }

    public function show(Role $role): RoleResource
    {
        return new RoleResource(
            $this->roles->withUserCount(Role::query())->with('permissions')->findOrFail($role->getKey())
        );
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($request->user(), $request->validated());

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        return new RoleResource($this->roles->update($request->user(), $role, $request->validated()));
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->roles->delete($request->user(), $role);

        return response()->json(null, 204);
    }
}
