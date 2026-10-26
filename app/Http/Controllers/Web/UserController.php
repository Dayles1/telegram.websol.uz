<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\Role as RoleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\Role;
use App\Models\User;
use App\Queries\DeliveryStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Superadmin: the panel's users. */
final class UserController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);
        $search = trim((string) $request->query('search'));

        $users = User::query()
            ->with('role')
            ->withCount(['phones' => fn ($q) => $q->whereNotNull('authorized_at'), 'catalogs'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $month = collect(DeliveryStats::between(now()->subDays(29)->startOfDay(), now())->byUser(500))->keyBy('user_id');

        return view('users.index', compact('users', 'search', 'month'));
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('users.form', ['user' => new User]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        Gate::authorize('create', User::class);
        User::query()->create($this->attributes($request));

        return redirect()->route('users.index')->with('success', __('users.saved'));
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);

        return view('users.form', ['user' => $user->load('role')]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);
        $user->update($this->attributes($request));

        return redirect()->route('users.index')->with('success', __('users.saved'));
    }

    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);
        $user->delete();

        return redirect()->route('users.index')->with('success', __('users.deleted'));
    }

    /** @return array<string, mixed> */
    private function attributes(UserRequest $request): array
    {
        $role = Role::query()->firstOrCreate(['name' => RoleName::from((string) $request->input('role'))->value]);
        $data = $request->safe()->only(['name', 'email']) + ['role_id' => $role->id];
        if ($request->filled('password')) {
            $data['password'] = $request->input('password');
        }

        return $data;
    }
}
