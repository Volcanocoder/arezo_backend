<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotificationRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['notification_id', 'user_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
