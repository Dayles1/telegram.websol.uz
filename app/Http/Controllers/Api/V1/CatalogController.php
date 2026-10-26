<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Catalog;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class CatalogController extends Controller
{
    // GET /api/catalogs
    public function index(Request $request)
    {
        $tab     = $request->get('tab', 'my'); // my | all
        $search  = $request->query('search');
        $perPage = $request->query('per_page', 20);

        $user = Auth::user();

        // 1. O‘zimniki
        $myQuery = Catalog::with('user:id,name')
            ->where('user_id', $user->id);

        // 2. Boshqalarniki (faqat superadmin ko‘radi)
        $allQuery = null;
        if ($user->hasRole('superadmin')) {
            $allQuery = Catalog::with('user:id,name')
                ->where('user_id', '!=', $user->id);
        }

        // Qidiruv
        if ($search) {
            $myQuery->where('title', 'like', "%{$search}%");

            if ($allQuery) {
                $allQuery->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$search}%"));
                });
            }
        }

        $myCatalogs  = $myQuery->latest()->paginate($perPage, ['*'], 'my_page');
        $allCatalogs = $allQuery ? $allQuery->latest()->paginate($perPage, ['*'], 'all_page') : null;

        return response()->json([
            'success' => true,
            'data' => [
                'tab'          => $tab,
                'my_catalogs'  => $myCatalogs,
                'all_catalogs' => $allCatalogs,
                'can_see_all'  => $user->hasRole('superadmin'),
            ]
        ]);
    }

    // POST /api/catalogs
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'peers' => 'nullable|array',
            'peers.*' => 'string',
        ]);

        $catalog = Catalog::create([
            'title'   => $request->title,
            'peers'   => $request->peers ?? [],
            'user_id' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Katalog muvaffaqiyatli yaratildi',
            'data'    => $catalog->load('user')
        ], 201);
    }

    // GET /api/catalogs/{id}
    public function show(Catalog $catalog)
    {
        $this->authorizeAccess($catalog);

        return response()->json([
            'success' => true,
            'data'    => $catalog->load('user')
        ]);
    }

    // PUT/PATCH /api/catalogs/{id}
    public function update(Request $request, Catalog $catalog)
    {
        $this->authorizeAccess($catalog);

        $request->validate([
            'title' => 'required|string|max:255',
            'peers' => 'nullable|array',
            'peers.*' => 'string',
        ]);

        $catalog->update([
            'title' => $request->title,
            'peers' => $request->peers ?? [],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Katalog yangilandi',
            'data'    => $catalog->load('user')
        ]);
    }

    // DELETE /api/catalogs/{id}
    public function destroy(Catalog $catalog)
    {
        $this->authorizeAccess($catalog);

        $catalog->delete();

        return response()->json([
            'success' => true,
            'message' => 'Katalog o‘chirildi'
        ]);
    }

    // POST /api/catalogs/duplicate
    public function duplicate(Request $request)
    {
        $user = Auth::user();

        if (!$user->hasRole('superadmin')) {
            return response()->json([
                'success' => false,
                'message' => 'Ruxsat yo‘q'
            ], 403);
        }

        $request->validate([
            'catalog_id' => 'required|exists:catalogs,id',
            'user_ids'   => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id'
        ]);

        $original = Catalog::findOrFail($request->catalog_id);

        foreach ($request->user_ids as $userId) {
            $new = $original->replicate();
            $new->user_id = $userId;
            $new->save();
        }

        return response()->json([
            'success' => true,
            'message' => count($request->user_ids) . ' ta foydalanuvchiga nusxa ko‘chirildi'
        ]);
    }

    // Helper: faqat egasi yoki superadmin ko‘ra o‘zgartira oladi
    private function authorizeAccess(Catalog $catalog)
    {
        if (Auth::id() !== $catalog->user_id && !Auth::user()->hasRole('superadmin')) {
            abort(403, 'Ruxsat yo‘q');
        }
    }
}