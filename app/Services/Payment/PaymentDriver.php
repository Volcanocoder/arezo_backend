<?php

namespace App\Services\Payment;

use App\Models\Donation;

interface PaymentDriver
{
    /** درخواست پرداخت؛ authority و آدرس انتقال کاربر را برمی‌گرداند. */
    public function request(Donation $donation, string $callbackUrl, string $description): array;

    /** تأیید پرداخت؛ شماره مرجع را برمی‌گرداند یا PaymentException پرتاب می‌کند. */
    public function verify(Donation $donation, string $authority): string;
}
