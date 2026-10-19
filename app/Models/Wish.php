<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Wish extends Model
{
    protected $fillable = [
        'title',
        'category',
        'story',
        'city',
        'requested_amount',
        'urgency',
        'user_id',
        'applicant_name',
        'applicant_mobile',
        'applicant_email',
        'identity_document_path',
        'evidence_document_path',
        'status',
        'review_note',
        'reviewed_at',
        'description',
        'applicant_relation',
        'wish_image_path',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function donations(): HasMany
{
    return $this->hasMany(Donation::class);
}

// گفتگوهای مربوط به این آرزو
public function conversations(): HasMany
{
    return $this->hasMany(Conversation::class);
}

}
