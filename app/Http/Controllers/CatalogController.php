<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Catalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CatalogController extends Controller
{
    public function index(Request $request)
{
    $tab = $request->get('tab', 'my'); // default "my"
    $search = $request->get('search', null);

    $queryMy = Catalog::with('user')->where('user_id', auth()->id());
    $queryAll = null;
    $users = [$request->user()];

    if (Auth::user()->hasRole('superadmin')) {
        // admin: "all" shows other users' catalogs (old behavior preserved)
        $users = User::all();
        $queryAll = Catalog::with('user')->where('user_id', '!=', auth()->id());
        if ($search) {
            $queryAll->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->where('name', 'like', "%{$search}%");
                  });
            });
        }
    } else {
        // non-admin: "all" shows global catalogs (is_global = true)
        $queryAll = Catalog::with('user')->where('is_global', true);
        if ($search) {
            $queryAll->where('title', 'like', "%{$search}%")
                     ->orWhereHas('user', function ($u) use ($search) {
                         $u->where('name', 'like', "%{$search}%");
                     });
        }
    }

    // Apply search to "my" (common for both)
    if ($search) {
        $queryMy->where('title', 'like', "%{$search}%");
    }

    // paginate with query string so search/tab are preserved
    $myCatalogs  = $queryMy->orderBy('created_at', 'desc')->paginate(20, ['*'], 'myPage')->withQueryString();
    $allCatalogs = $queryAll ? $queryAll->orderBy('created_at', 'desc')->paginate(20, ['*'], 'allPage')->withQueryString() : collect();

    return view('catalogs.index', compact('myCatalogs', 'allCatalogs', 'users', 'tab'));
}


    public function removePeerFromCatalog(Request $request)
    {
        // Validate incoming request
        $validator = Validator::make($request->all(), [
            'catalog_id' => ['required', 'integer', 'exists:catalogs,id'],
            'peer'       => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $catalogId = $request->input('catalog_id');
        $peerToRemove = $request->input('peer');

        // Superadmin bo'lsa, faqat ID bo'yicha topamiz (hech qanday user_id cheklovi yo'q)
        // Oddiy user bo'lsa, faqat o'z katalogini topadi
        $query = Catalog::where('id', $catalogId);

        if (auth()->user()->role->name !== 'superadmin') {  // Agar superadmin BO'LSA, bu shart ishlamaydi
            $query->where('user_id', auth()->id());
        }
        $catalog = $query->first();

        if (!$catalog) {
            return response()->json([
                'status'  => 'error',
                'message' => '"Catalog" topilmadi, yoki sizniki emas',
            ], 404);
        }

        // peers allaqachon array bo'ladi (casts: array tufayli)
        $peers = $catalog->peers ?? [];

        // Agar peer mavjud bo'lmasa — idempotent tarzda success qaytaramiz
        if (!in_array($peerToRemove, $peers)) {
            return response()->json([
                'status'  => 'success',
                'message' => 'Peer was not found in the catalog (already removed or never existed).',
                'catalog' => [
                    'id'    => $catalog->id,
                    'title' => $catalog->title,
                    'peers' => $peers,
                ],
            ]);
        }

        // Peer ni olib tashlash
        $updatedPeers = array_values(array_diff($peers, [$peerToRemove]));

        // Yangilash
        $catalog->peers = $updatedPeers;
        $catalog->save();

        return response()->json([
            'status'  => 'success',
            'message' => 'Peer successfully removed from catalog.',
            'catalog' => [
                'id'           => $catalog->id,
                'title'        => $catalog->title,
                'peers'        => $updatedPeers,
                'removed_peer' => $peerToRemove,
            ],
        ]);
    }



    public function create()
    {
        $catalog = Catalog::where('user_id', Auth::user()->id)->get();
        $canMakeGlobal = Auth::user()->hasRole('superadmin');
        return view('catalogs.create', compact('catalog','canMakeGlobal'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'peers' => 'nullable|array',
            'peers.*' => 'string|max:255',
            'is_global' => 'nullable|boolean',
            'timer_minutes' => 'nullable|integer|min:1|max:10080'
        ]);

        // Agar foydalanuvchi superadmin bo'lmasa, is_global ni e'tiborsiz qoldiramiz
        $isGlobal = $request->boolean('is_global') && Auth::user()->hasRole('superadmin');
        $timer = $request->input('timer_minutes');
        

        // peers tozalash: trim, unique, filter empty
        $rawPeers = $request->input('peers', []);
        $cleanPeers = collect($rawPeers)
            ->map(fn($p) => trim((string)$p))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $catalog = Catalog::create([
            'title' => $request->title,
            'user_id' => Auth::id(),
            'peers' => $cleanPeers,
            'is_global' => $isGlobal,
            'timer_minutes' => $timer,
        ]);

        return redirect()->route('catalogs.index')->with('success', 'Catalog yaratildi');
    }

    public function edit(Catalog $catalog)
    {
        if (! Auth::user()->hasRole('superadmin') && $catalog->user_id !== Auth::id()) {
            abort(403, 'Sizda bu catalogni tahrirlash huquqi yo‘q.');
        }

        $canMakeGlobal = Auth::user()->hasRole('superadmin');

        return view('catalogs.edit', compact('catalog', 'canMakeGlobal'));
    }

    public function update(Request $request, Catalog $catalog)
    {
        if (! Auth::user()->hasRole('superadmin') && $catalog->user_id !== Auth::id()) {
            abort(403, 'Sizda bu catalogni tahrirlash huquqi yo‘q.');
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'peers' => 'nullable|array',
            'peers.*' => 'string|max:255',
            'is_global' => 'nullable|boolean',
            'timer_minutes' => 'nullable|integer|min:1|max:10080'
        ]);

        $isGlobal = $request->boolean('is_global') && Auth::user()->hasRole('superadmin');
        $timer = $request->input('timer_minutes');
        

        $rawPeers = $request->input('peers', []);
        $cleanPeers = collect($rawPeers)
            ->map(fn($p) => trim((string)$p))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $catalog->update([
            'title' => $request->title,
            'peers' => $cleanPeers,
            'is_global' => $isGlobal,
            'timer_minutes' => $timer,
        ]);

        return redirect()->route('catalogs.index')->with('success', 'Catalog yangilandi');
    }

    public function destroy(Catalog $catalog)
    {
        if (! Auth::user()->hasRole('superadmin') && $catalog->user_id !== Auth::id()) {
            abort(403, 'Sizda bu catalogni oʻchirish huquqi yo‘q.');
        }

        $catalog->delete();

        return redirect()->route('catalogs.index')->with('success', 'Catalog o‘chirildi');
    }
    public function duplicate(Request $request)
    {
        $request->validate([
            'catalog_id' => 'required|exists:catalogs,id',
            'user_ids'  => 'required|array',
            'user_ids.*' => 'exists:users,id'
        ]);

        $usersIds   = User::whereIn('id', $request->user_ids)->pluck('id');
        if ($usersIds->isEmpty()) {
            return back()->with('error', 'No users selected!');
        }
        $catalog = Catalog::findOrFail($request->catalog_id);
        foreach ($usersIds as $id) {
            $newCatalog = $catalog->replicate();
            $newCatalog->user_id = $id;
            $newCatalog->is_global=false;
            $newCatalog->save();
        }
        return back()->with('success', 'Catalog cloned successfully!');
    }
}
