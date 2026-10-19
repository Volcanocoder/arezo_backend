<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'mobile',
        'password',
        'city',
        'bio',
        'avatar_path',
        'wish_updates',
        'new_messages',
        'wish_completed',
        'email_updates',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'wish_updates' => 'boolean',
        'new_messages' => 'boolean',
        'wish_completed' => 'boolean',
        'email_updates' => 'boolean',
    ];
}

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin'], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function startedConversations(): HasMany
{
    return $this->hasMany(Conversation::class, 'donor_id');
}

// گفتگوهایی که برای صاحب آرزو هستند
public function receivedConversations(): HasMany
{
    return $this->hasMany(Conversation::class, 'wish_owner_id');
}

// پیام‌هایی که کاربر ارسال کرده است
public function sentMessages(): HasMany
{
    return $this->hasMany(Message::class, 'sender_id');
}
}

