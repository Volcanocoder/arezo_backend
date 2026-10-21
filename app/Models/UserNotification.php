<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserNotification extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'body',
        'type',
        'link',
        'created_by',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(UserNotificationRead::class, 'notification_id');
    }

    /** اعلان‌های قابل‌مشاهده برای یک کاربر: اختصاصی + همگانی‌های بعد از عضویتش. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('user_notifications.user_id', $user->id)
                ->orWhere(function (Builder $broadcast) use ($user) {
                    $broadcast->whereNull('user_notifications.user_id')
                        ->where('user_notifications.created_at', '>=', $user->created_at);
                });
        });
    }

    /** ساخت اعلان اختصاصی؛ برای رویدادهای سیستمی (مثل نتیجه بررسی آرزو). */
    public static function notify(int $userId, string $title, string $body, string $type = 'info', ?string $link = null): self
    {
        return static::create([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'link' => $link,
        ]);
    }
}
