<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final class AuthController extends Controller
{
    /** POST /api/login -> {user, token} */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();
        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['status' => 'error', 'message' => __('auth.failed')], 401);
        }

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('api_token')->plainTextToken,
        ]);
    }

    /** POST /api/logout: revokes the token used for this call. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    }
}
