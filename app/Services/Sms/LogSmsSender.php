<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * برای توسعه: کد را فقط در لاگ می‌نویسد و پیامکی ارسال نمی‌کند.
 */
class LogSmsSender implements SmsSender
{
    public function sendOtp(string $mobile, string $code): void
    {
        Log::info("OTP برای {$mobile}: {$code}");
    }
}
