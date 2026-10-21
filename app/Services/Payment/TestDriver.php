<?php

namespace App\Services\Payment;

use App\Models\Donation;
use Illuminate\Support\Str;

/** درگاه آزمایشی بدون پول واقعی؛ فقط در محیط غیر production. */
class TestDriver implements PaymentDriver
{
    public function request(Donation $donation, string $callbackUrl, string $description): array
    {
        $authority = 'TEST'.strtoupper(Str::random(20));

        return [
            'authority' => $authority,
            'url' => $callbackUrl.(str_contains($callbackUrl, '?') ? '&' : '?').'Authority='.$authority.'&Status=OK',
        ];
    }

    public function verify(Donation $donation, string $authority): string
    {
        return 'T'.$donation->id.'-'.substr($authority, -6);
    }
}
