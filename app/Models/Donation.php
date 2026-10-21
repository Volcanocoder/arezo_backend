<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Donation extends Model
{
    protected $fillable = [
        'wish_id',
        'user_id',
        'amount',
        'status',
        'gateway',
        'payment_gateway_id',
        'gateway_authority',
        'gateway_reference',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * آرزویی که این کمک برای آن ثبت شده است
     */
    public function wish(): BelongsTo
    {
        return $this->belongsTo(Wish::class);
    }

    /**
     * کاربری که این کمک را انجام داده است
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}