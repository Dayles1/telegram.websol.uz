<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Ban;
use App\Models\User;
use App\Models\Department;

class CheckUserBan
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();

        if ($user->role?->name === 'superadmin') {
            return $next($request);
        }

        if ($this->hasActiveBan(User::class, $user->id)) {
            return $this->logout($request);
        }

        if ($user->department_id && $this->hasActiveBan(Department::class, $user->department_id)) {
            return $this->logout($request);
        }
        

        return $next($request);
    }

    private function hasActiveBan(string $type, int $id): bool
{
    Ban::query()
        ->where('bannable_type', $type)
        ->where('bannable_id', $id)
        ->where('active', false)
        ->whereNotNull('starts_at')
        ->where('starts_at', '<=', now())
        ->update([
            'active' => true,
        ]);

    // 🔥 2. real active ban borligini tekshiramiz
    return Ban::query()
        ->where('bannable_type', $type)
        ->where('bannable_id', $id)
        ->where(function ($q) {
            $q->where('active', true)
              ->orWhere(function ($q2) {
                  $q2->whereNotNull('starts_at')
                     ->where('starts_at', '<=', now());
              });
        })
        ->where(function ($q) {
            $q->whereNull('until')
              ->orWhere('until', '>', now());
        })
        ->exists();
}

    private function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->withErrors([
            'email' => __('messages.admin.banned'),
        ]);
    }
}