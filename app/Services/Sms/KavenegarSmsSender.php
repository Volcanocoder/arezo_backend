<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class KavenegarSmsSender implements SmsSender
{
    public function sendOtp(string $mobile, string $code): void
    {
        $apiKey = config('sms.kavenegar.api_key');

        if (! $apiKey) {
            throw new RuntimeException('KAVENEGAR_API_KEY تنظیم نشده است.');
        }

        $response = Http::timeout(10)->asForm()->post(
            "https://api.kavenegar.com/v1/{$apiKey}/verify/lookup.json",
            [
                'receptor' => $mobile,
                'token' => $code,
                'template' => config('sms.kavenegar.otp_template'),
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException('ارسال پیامک ناموفق بود.');
        }
    }
}
