<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGateway extends Model
{
    public const DRIVERS = ['zarinpal', 'test'];

    protected $fillable = [
        'driver', 'title', 'merchant_id', 'is_sandbox', 'is_active', 'is_default', 'description',
    ];

    protected $hidden = ['merchant_id'];

    protected function casts(): array
    {
        return [
            'merchant_id' => 'encrypted',
            'is_sandbox' => 'boolean',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** نمایش پوشیده‌ی مرچنت‌کد؛ مقدار کامل هیچ‌وقت به فرانت برنمی‌گردد. */
    public function maskedMerchant(): ?string
    {
        $value = $this->merchant_id;

        if (! $value) {
            return null;
        }

        return strlen($value) <= 8
            ? str_repeat('•', strlen($value))
            : substr($value, 0, 4).str_repeat('•', 8).substr($value, -4);
    }
}
