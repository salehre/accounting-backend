<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'roles';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }
}
