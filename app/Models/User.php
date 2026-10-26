<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role as RoleName;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role_id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function phones(): HasMany
    {
        return $this->hasMany(UserPhone::class);
    }

    public function catalogs(): HasMany
    {
        return $this->hasMany(Catalog::class);
    }

    public function avatar(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    public function roleName(): RoleName
    {
        return RoleName::tryFrom((string) $this->role?->name) ?? RoleName::User;
    }

    public function isSuperadmin(): bool
    {
        return $this->roleName() === RoleName::Superadmin;
    }

    /** Kept for old call sites and the API: hasRole('superadmin'). */
    public function hasRole(string $role): bool
    {
        return $this->roleName()->value === strtolower($role);
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->avatar?->path ? Storage::disk('public')->url($this->avatar->path) : null);
    }

    protected function initials(): Attribute
    {
        return Attribute::get(function (): string {
            $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];
            $letters = array_map(fn (string $p) => mb_substr($p, 0, 1), array_slice(array_filter($parts), 0, 2));

            return mb_strtoupper(implode('', $letters) ?: mb_substr((string) $this->email, 0, 1));
        });
    }
}
