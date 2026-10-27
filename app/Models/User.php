<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    // Our own table (see db/sql/001_core.sql): no updated_at column,
    // and the password column is called password_hash.
    protected $table = 'users';

    public $timestamps = false;

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    // Permissions look like "entries.read". "*" grants everything,
    // and "entries.*" grants every permission starting with "entries.".
    public function hasPermission(string $required): bool
    {
        foreach ($this->role->permissions ?? [] as $granted) {
            if ($granted === '*' || $granted === $required) {
                return true;
            }
            if (str_ends_with($granted, '.*')
                && str_starts_with($required, substr($granted, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
