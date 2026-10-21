<?php

namespace App\Services\Payment;

use App\Models\PaymentGateway;

class PaymentManager
{
    public function driverFor(PaymentGateway $gateway): PaymentDriver
    {
        return match ($gateway->driver) {
            'zarinpal' => new ZarinpalDriver($gateway),
            'test' => app()->isProduction()
                ? throw new PaymentException('درگاه آزمایشی در محیط واقعی فعال نیست.')
                : new TestDriver(),
            default => throw new PaymentException('نوع درگاه پشتیبانی نمی‌شود.'),
        };
    }

    /** درگاه انتخاب‌شده یا درگاه پیش‌فرض (فقط فعال‌ها). */
    public function resolve(?int $id = null): ?PaymentGateway
    {
        $query = PaymentGateway::query()->active();

        if ($id) {
            return $query->whereKey($id)->first();
        }

        return $query->orderByDesc('is_default')->orderBy('id')->first();
    }
}
