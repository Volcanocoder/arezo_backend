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
        'suspended_at' => 'datetime',
        'last_login_at' => 'datetime',
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

    public function wishes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Wish::class);
    }

    public function donations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Donation::class);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** آدرس عمومی تصویر پروفایل؛ بدون نیاز به storage:link. */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path
            ? url('/api/media/avatar/'.basename($this->avatar_path))
            : null;
    }

    /** فیلدهای امن کاربر برای پاسخ‌های احراز هویت. */
    public function publicData(): array
    {
        return $this->only(['id', 'name', 'email', 'mobile', 'role']) + [
            'avatar_url' => $this->avatarUrl(),
        ];
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

