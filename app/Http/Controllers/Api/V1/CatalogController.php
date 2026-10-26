<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogRequest;
use App\Models\Catalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Catalogs over the API, in the old response shapes. */
final class CatalogController extends Controller
{
    /** GET /api/catalogs?tab=my|all&search=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search'));
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $matches = fn (Builder $q) => $q->where(fn (Builder $w) => $w
            ->where('title', 'like', "%{$search}%")
            ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$search}%")));

        $mine = Catalog::query()->with('user:id,name')->where('user_id', $user->id)
            ->when($search !== '', fn (Builder $q) => $q->where('title', 'like', "%{$search}%"))
            ->latest()->paginate($perPage, ['*'], 'my_page');

        // Others: every catalog for a superadmin, the global ones for everybody else.
        $all = Catalog::query()->with('user:id,name')->where('user_id', '!=', $user->id)
            ->when(! $user->isSuperadmin(), fn (Builder $q) => $q->where('is_global', true))
            ->when($search !== '', $matches)
            ->latest()->paginate($perPage, ['*'], 'all_page');

        return response()->json([
            'success' => true,
            'data' => [
                'tab' => $request->query('tab', 'my'),
                'my_catalogs' => $mine,
                'all_catalogs' => $all,
                'can_see_all' => $user->isSuperadmin(),
            ],
        ]);
    }

    public function store(CatalogRequest $request): JsonResponse
    {
        $catalog = $request->user()->catalogs()->create($request->catalogData());

        return response()->json(['success' => true, 'message' => 'Katalog muvaffaqiyatli yaratildi', 'data' => $catalog->load('user:id,name')], 201);
    }

    public function show(Catalog $catalog): JsonResponse
    {
        Gate::authorize('view', $catalog);

        return response()->json(['success' => true, 'data' => $catalog->load('user:id,name')]);
    }

    public function update(CatalogRequest $request, Catalog $catalog): JsonResponse
    {
        Gate::authorize('update', $catalog);
        $catalog->update($request->catalogData());

        return response()->json(['success' => true, 'message' => 'Katalog yangilandi', 'data' => $catalog->load('user:id,name')]);
    }

    public function destroy(Catalog $catalog): JsonResponse
    {
        Gate::authorize('delete', $catalog);
        $catalog->delete();

        return response()->json(['success' => true, 'message' => 'Katalog o‘chirildi']);
    }

    /** POST /api/catalogs/duplicate {catalog_id, user_ids[]} (superadmin) */
    public function duplicate(Request $request): JsonResponse
    {
        Gate::authorize('superadmin');
        $data = $request->validate([
            'catalog_id' => ['required', 'integer', 'exists:catalogs,id'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $original = Catalog::query()->findOrFail($data['catalog_id']);
        foreach (array_unique($data['user_ids']) as $userId) {
            $original->replicate()->fill(['user_id' => $userId, 'is_global' => false])->save();
        }

        return response()->json(['success' => true, 'message' => count(array_unique($data['user_ids'])).' ta foydalanuvchiga nusxa ko‘chirildi']);
    }
}
