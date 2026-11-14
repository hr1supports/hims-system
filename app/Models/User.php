<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * HIMS accounts (the existing "users" table). Signing in is still handled by the HIMS backend
 * (password + email code / authenticator app); Laravel uses this model to read account details.
 */
class User extends Authenticatable
{
    use HasFactory;

    protected $table = 'users';
    protected $guarded = [];
    protected $hidden = ['password_hash', 'totp_secret', 'remember_token'];

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }
}
