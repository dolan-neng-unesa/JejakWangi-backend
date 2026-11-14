<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserModelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_admin', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class UserModel extends Authenticatable
{
    /** @use HasFactory<UserModelFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    // Nama kelas modelnya UserModel (bukan User), tapi tabelnya tetap `users`
    // (migration bawaan Laravel) — tanpa baris ini Eloquent menebak nama tabel
    // dari nama kelas ("user_models") dan salah.
    protected $table = 'users';

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
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
