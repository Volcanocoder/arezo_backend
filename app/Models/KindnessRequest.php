<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KindnessRequest extends Model
{
    protected $fillable = [
        'kindness_item_id', 'user_id', 'message', 'status',
        'review_note', 'reviewed_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(KindnessItem::class, 'kindness_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
