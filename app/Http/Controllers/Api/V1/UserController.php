<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    // Userlar ro'yxati
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

        return response()->json($users);
    }

    // Rolelar ro'yxati (create/edit uchun)
    public function roles()
    {
        $roles = Role::all();
        return response()->json($roles);
    }

    // Yangi user yaratish
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'role_id'  => 'required|exists:roles,id'
        ]);

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user->load('role')
        ], 201);
    }

    // Faqat bitta userni ko'rish
    public function show(User $user)
    {
        return response()->json($user->load('role'));
    }

    // User ma'lumotlarini update qilish
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email,' . $user->id,
            'role_id'  => 'required|exists:roles,id',
            'password' => 'nullable|min:6'
        ]);

        if ($request->filled('password')) {
            $validated['password'] = Hash::make($request->password);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user->load('role')
        ]);
    }

    // Userni o'chirish
    public function destroy(User $user)
    {
        if ($user->role->name === 'superadmin') {
            return response()->json(['error' => 'Cannot delete superadmin user.'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    // Auth user profili
    public function profile()
{
    $user = Auth::user()->load('phones.catalogs', 'catalogs');

    $data = $user->toArray();

    // avatar URL qo‘shish (modelni o‘zgartirmaymiz)
    $data['avatar_url'] = $user->avatar && $user->avatar->path
        ? asset('storage/' . $user->avatar->path)
        : "https://ui-avatars.com/api/?name=" . strtoupper(substr($user->name, 0, 1)) . "&background=0D8ABC&color=fff&size=128";

    return response()->json($data);
}

}
