<?php
namespace App\Http\Controllers;

use view;
use Throwable;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Services\TelegramAvatarFetcher;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');

        $query = User::with('role');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%");
            });
        }

        $users = $query->paginate(20)->withQueryString();

        return view('users.index', compact('users', 'search'));
    }


    public function create()
    {
        $roles = Role::all();
        return view('users.create', compact('roles'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'role_id'  => 'required|exists:roles,id'
        ]);
        $validated['password'] = Hash::make($validated['password']);

        $user=User::create([
            'name'=> $validated['name'],
            'email'=> $validated['email'],
            'password'=> $validated['password'],
            'role_id'=>$validated['role_id']
        ]);


        return redirect()->route('users.index')->with('success', 'User created.');
        // return response()->json('success');
    }

    public function show(User $user)
    {
        $user->load('role');
        return view('users.show', compact('user'));
    }

    public function edit(User $user)
    {
        $roles = Role::all();
        return view('users.edit', compact('user', 'roles'));
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email,' . $user->id,
            'role_id'  => 'required|exists:roles,id',
            'password' => 'nullable|min:6'
        ]);
        

        if ($request->password) {
            $validated['password'] = Hash::make($request->password);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    public function destroy(User $user)
    {
        if($user->role->name === 'superadmin'){
            return redirect()->route('users.index')->with('error', 'Cannot delete superadmin user.');
        }
        $user->delete();
        return redirect()->route('users.index')->with('success', 'User deleted.');
    }

    public function fetchAvatarFromTelegram(Request $request, TelegramAvatarFetcher $fetcher)
{
    $user = Auth::user();

    $request->validate([
        'phone_id' => ['required','integer'],
    ]);

    $phoneId = (int)$request->input('phone_id');

    // Basic ownership check
    $phone = $user->phones()->where('id', $phoneId)->first();
    if (!$phone) {
        return response()->json(['success' => false, 'message' => 'This phone does not belong to you.'], 403);
    }

    // Call service synchronously (can be slow if Madeline starts — but returns immediate result)
    $result = $fetcher->fetchByPhoneId($phoneId);

    if ($result['success']) {
        return response()->json([
            'success' => true,
            'message' => $result['message'] ?? 'Avatar loaded',
            'avatar_url' => $result['url'] ?? null,
            'avatar_path' => $result['path'] ?? null,
        ]);
    } else {
        return response()->json([
            'success' => false,
            'message' => $result['message'] ?? 'Failed to load avatar',
        ], 500);
    }
}

    
    public function profile()
    {
        $user = Auth::user()->load('phones.catalogs','catalogs','avatar');
        return view('users.profile', compact('user'));
    }

    public function profileUpdate(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required','email','max:255','unique:users,email,'.$user->id],
            'password' => ['nullable','string','min:6','confirmed'],
        ]);

        $user->name = $request->input('name');
        $user->email = $request->input('email');

        if ($request->filled('password')) {
            $user->password = Hash::make($request->input('password'));
        }

        $user->save();

        return back()->with('success', 'Profil muvaffaqiyatli yangilandi.');
    }

    public function avatarUpdate(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'avatar' => ['required','image','mimes:jpeg,jpg,png,gif','max:2048'],
        ]);

        $file = $request->file('avatar');
        $filename = 'avatar_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();

        try {
            // saqlash
            $path = $file->storeAs('avatars', $filename, 'public'); // e.g. avatars/avatar_1_1600000000.jpg

            // eski faylni o'chirish
            $old = $user->avatar;
            if ($old && !empty($old->path)) {
                Storage::disk('public')->delete($old->path);
            }

            if ($old) {
                $old->update(['path' => $path]);
            } else {
                $user->avatar()->create(['path' => $path]);
            }

            // public URL qaytaramiz (frontend uni to'g'ridan-to'g'ri ishlatadi)
            $url = Storage::disk('public')->url($path); // /storage/avatars/...

            // Agar AJAX (fetch) bo'lsa JSON qaytarsin
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'path' => $path,
                    'url' => $url,
                ]);
            }

            return back()->with('success', 'Avatar muvaffaqiyatli yangilandi.');

        } catch (Throwable $e) {
            // log qilish
            Log::error('Avatar upload error: '.$e->getMessage());

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'error' => 'Server error while saving avatar.'], 500);
            }

            return back()->withErrors(['avatar' => 'Server error while saving avatar.']);
        }
    }

    public function avatarDelete(Request $request)
    {
        $user = Auth::user();

        $old = $user->avatar;
        if ($old && !empty($old->path)) {
            Storage::disk('public')->delete($old->path);
            $old->delete();
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Avatar o‘chirildi.',
            ]);
        }

        return back()->with('success', 'Avatar o‘chirildi.');
    }

}

