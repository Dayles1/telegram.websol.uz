<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Storage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens,HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
    // app/Models/User.php
public function isSuperAdmin(): bool
{
    return $this->role === 'superadmin' || $this->is_superadmin === true; // yoki sizning logikangizga moslashtiring
    // Masalan: return $this->hasRole('superadmin'); agar Spatie Permission ishlatayotgan bo'lsangiz
}
    public function phones()
    {
        return $this->hasMany(UserPhone::class);
    }
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    // Helper methods
    public function hasRole($role)
    {
        return strtolower($this->role->name) === strtolower($role);
    }

    public function isAdmin()
    {
        return $this->hasRole('superadmin');
    }

    public function isUser()
    {
        return $this->hasRole('user');
    }
    public function catalogs()
    {
        return $this->hasMany(Catalog::class);
    }
    public function avatar()
    {
        return $this->morphOne(Image::class, 'imageable');
    }
    public function getAvatarUrlAttribute()
    {
        // agar avatar relation mavjud va path to'ldirilgan bo'lsa Storage::url qaytaradi
        if ($this->relationLoaded('avatar') ? $this->avatar?->path : $this->avatar()->exists() && $this->avatar) {
            $path = $this->avatar->path; // misol: "avatars/abc.jpg"
            // agar path allaqachon "avatars/..." bo'lsa va storage link bor bo'lsa:
            return Storage::url($path);
        }

        return null;
    }
    

    // foydali yordamchi: avatar bormi?
    public function hasAvatar(): bool
    {
        // relation loaded bo'lsa relation orqali tekshiramiz, aks holda DB so'rov
        if ($this->relationLoaded('avatar')) {
            return (bool) $this->avatar;
        }

        return $this->avatar()->exists();
    }

    // agar kerak bo'lsa: initial (ism birinchi harfi, katta)
    public function getInitialAttribute(): string
    {
        return strtoupper(substr($this->name ?? ($this->email ?? 'U'), 0, 1));
    }
}
