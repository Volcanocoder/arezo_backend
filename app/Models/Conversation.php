<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'wish_id',
        'donor_id',
        'wish_owner_id',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    // آرزویی که گفتگو درباره آن است
    public function wish(): BelongsTo
    {
        return $this->belongsTo(Wish::class);
    }

    // کاربری که قصد کمک دارد
    public function donor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'donor_id');
    }

    // صاحب آرزو
    public function wishOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'wish_owner_id');
    }

    // پیام‌های این گفتگو
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}