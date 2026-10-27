<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role as RoleName;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Password;

/** Users over the API. Was open to any token; now superadmin only (profile excepted). */
final class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);
        $search = trim((string) $request->input('search'));

        return response()->json(User::query()->with('role')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->paginate(20)->withQueryString());
    }

    public function roles(): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        return response()->json(Role::all());
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        return response()->json($user->load('role'));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', User::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'role_id' => ['required', $this->assignableRole($request)],
        ]);

        $user = User::query()->create($data);

        return response()->json(['message' => 'User created successfully', 'user' => $user->load('role')], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user)],
            'role_id' => ['required', $this->assignableRole($request)],
            'password' => ['nullable', Password::min(8)],
        ]);
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json(['message' => 'User updated successfully', 'user' => $user->load('role')]);
    }

    public function destroy(User $user): JsonResponse
    {
        Gate::authorize('delete', $user);
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    /** role_id of a role this email may get: superadmin only for the configured superadmin email. */
    private function assignableRole(Request $request): Exists
    {
        $names = array_map(fn (RoleName $r) => $r->value, RoleName::assignableTo((string) $request->input('email')));

        return Rule::exists('roles', 'id')->whereIn('name', $names);
    }

    /** GET /api/profile */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user()->load(['phones' => fn ($q) => $q->connected(), 'catalogs', 'avatar']);
        $data = $user->toArray();
        $data['avatar_url'] = $user->avatar?->path
            ? Storage::disk('public')->url($user->avatar->path)
            : 'https://ui-avatars.com/api/?name='.urlencode($user->initials).'&background=0b8797&color=fff&size=128';

        return response()->json($data);
    }
}
