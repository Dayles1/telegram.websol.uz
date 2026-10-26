<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/** My name, email, password, avatar, and the API tokens external systems use. */
final class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user()->load('avatar'),
            'tokens' => $request->user()->tokens()->latest()->get(['id', 'name', 'last_used_at', 'created_at']),
        ]);
    }

    public function update(ProfileRequest $request): RedirectResponse
    {
        $data = $request->safe()->only(['name', 'email']);
        if ($request->filled('password')) {
            $data['password'] = $request->input('password');
        }
        $request->user()->update($data);

        return back()->with('success', __('profile.saved'));
    }

    public function avatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'max:2048']]);
        $user = $request->user();

        $path = $request->file('avatar')->store('avatars', 'public');
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar->path);
            $user->avatar->update(['path' => $path]);
        } else {
            $user->avatar()->create(['path' => $path]);
        }

        return response()->json(['ok' => true, 'url' => Storage::disk('public')->url($path)]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $avatar = $request->user()->avatar;
        if ($avatar) {
            Storage::disk('public')->delete($avatar->path);
            $avatar->delete();
        }

        return response()->json(['ok' => true]);
    }

    /** A new API token; its text is shown once. */
    public function createToken(Request $request): RedirectResponse
    {
        $name = $request->validate(['name' => ['required', 'string', 'max:100']])['name'];
        $token = $request->user()->createToken($name);

        return back()->with('new_token', $token->plainTextToken);
    }

    public function deleteToken(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return back()->with('success', __('profile.token_deleted'));
    }
}
