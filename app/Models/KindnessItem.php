<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KindnessItem extends Model
{
    public const CATEGORIES = [
        'لوازم خانه', 'پوشاک', 'کتاب و لوازم تحریر', 'لوازم کودک',
        'لوازم برقی', 'مبلمان', 'سایر',
    ];

    public const CONDITIONS = [
        'like_new' => 'در حد نو',
        'good' => 'سالم',
        'fair' => 'قابل استفاده',
    ];

    protected $fillable = [
        'user_id', 'title', 'category', 'description', 'condition', 'city',
        'image_path', 'status', 'review_note', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(KindnessRequest::class);
    }

    /** پیام برای همه مدیران (مثلاً آگهی یا درخواست تازه). */
    public static function notifyAdmins(string $title, string $body, string $link): void
    {
        User::query()
            ->whereIn('role', ['admin', 'super_admin'])
            ->pluck('id')
            ->each(fn (int $id) => UserNotification::notify($id, $title, $body, 'info', $link));
    }
}
