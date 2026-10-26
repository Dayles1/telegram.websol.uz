<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogRequest;
use App\Http\Resources\CatalogResource;
use App\Models\Catalog;
use App\Models\User;
use App\Sending\Peers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Peer lists. Tabs: "mine", "global" (shared by a superadmin), "others"
 * (superadmin only: every other user's catalogs).
 */
final class CatalogController extends Controller
{
    public const TABS = ['mine', 'global', 'others'];

    public function index(): View
    {
        return view('catalogs.index');
    }

    public function data(Request $request): JsonResponse
    {
        $user = $request->user();
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'mine';
        abort_if($tab === 'others' && ! $user->isSuperadmin(), 403);
        $search = trim((string) $request->query('search'));

        $catalogs = Catalog::query()
            ->with('user:id,name')
            ->when($tab === 'mine', fn (Builder $q) => $q->where('user_id', $user->id))
            ->when($tab === 'global', fn (Builder $q) => $q->where('is_global', true)->where('user_id', '!=', $user->id))
            ->when($tab === 'others', fn (Builder $q) => $q->where('user_id', '!=', $user->id))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('title', 'like', "%{$search}%")
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$search}%"))))
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'catalogs' => CatalogResource::collection($catalogs->getCollection()),
            'meta' => ['current_page' => $catalogs->currentPage(), 'last_page' => $catalogs->lastPage(), 'total' => $catalogs->total()],
            'counts' => [
                'mine' => Catalog::query()->where('user_id', $user->id)->count(),
                'global' => Catalog::query()->where('is_global', true)->where('user_id', '!=', $user->id)->count(),
                'others' => $user->isSuperadmin() ? Catalog::query()->where('user_id', '!=', $user->id)->count() : null,
            ],
        ]);
    }

    public function create(): View
    {
        return view('catalogs.form', ['catalog' => new Catalog(['peers' => []])]);
    }

    public function store(CatalogRequest $request): RedirectResponse
    {
        $catalog = $request->user()->catalogs()->create($request->catalogData());

        return redirect()->route('catalogs.index')
            ->with('success', __('catalogs.saved', ['title' => $catalog->title, 'count' => count($catalog->peerList())]));
    }

    public function edit(Catalog $catalog): View
    {
        Gate::authorize('update', $catalog);

        return view('catalogs.form', ['catalog' => $catalog]);
    }

    public function update(CatalogRequest $request, Catalog $catalog): RedirectResponse
    {
        Gate::authorize('update', $catalog);
        $catalog->update($request->catalogData());

        return redirect()->route('catalogs.index')
            ->with('success', __('catalogs.saved', ['title' => $catalog->title, 'count' => count($catalog->peerList())]));
    }

    public function destroy(Catalog $catalog): JsonResponse
    {
        Gate::authorize('delete', $catalog);
        $catalog->delete();

        return response()->json(['ok' => true, 'message' => __('catalogs.deleted')]);
    }

    /** A copy of a visible catalog for myself. */
    public function copy(Request $request, Catalog $catalog): JsonResponse
    {
        Gate::authorize('view', $catalog);
        $copy = $catalog->replicate();
        $copy->fill(['user_id' => $request->user()->id, 'is_global' => false])->save();

        return response()->json(['ok' => true, 'message' => __('catalogs.copied'), 'id' => $copy->id]);
    }

    /** Superadmin: give a copy to each chosen user. */
    public function distribute(Request $request, Catalog $catalog): JsonResponse
    {
        Gate::authorize('superadmin');
        $userIds = array_unique($request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ])['user_ids']);

        foreach ($userIds as $userId) {
            $catalog->replicate()->fill(['user_id' => $userId, 'is_global' => false])->save();
        }

        return response()->json(['ok' => true, 'message' => __('catalogs.distributed', ['count' => count($userIds)])]);
    }

    /** Removes one peer (from a send's detail page, after it failed). */
    public function removePeer(Request $request, Catalog $catalog): JsonResponse
    {
        Gate::authorize('update', $catalog);
        $peer = (string) $request->validate(['peer' => ['required', 'string']])['peer'];

        // Old catalogs keep peers as typed ("t.me/name"); sends store them normalized ("@name").
        $key = fn (string $p) => mb_strtolower(Peers::normalize($p));
        $kept = array_values(array_filter($catalog->peerList(), fn (string $p) => $key($p) !== $key($peer)));
        abort_if(count($kept) === count($catalog->peerList()), 404, __('catalogs.peer_not_in_catalog'));
        $catalog->update(['peers' => $kept]);

        return response()->json(['ok' => true, 'message' => __('catalogs.peer_removed', ['peer' => $peer])]);
    }

    /** Users a superadmin can hand a catalog to. */
    public function users(): JsonResponse
    {
        Gate::authorize('superadmin');

        return response()->json(User::query()->orderBy('name')->get(['id', 'name', 'email']));
    }
}
