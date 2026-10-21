<?php

return [

    /*
    | درایور ارسال پیامک: log (فقط در لاگ می‌نویسد؛ برای توسعه) یا kavenegar
    */
    'driver' => env('SMS_DRIVER', 'log'),

    'kavenegar' => [
        'api_key' => env('KAVENEGAR_API_KEY'),
        // نام قالب تأییدشده (verify lookup) در پنل کاوه‌نگار؛ توکن اول = کد
        'otp_template' => env('KAVENEGAR_OTP_TEMPLATE', 'verify'),
    ],

    'otp' => [
        'length' => 6,
        'ttl_minutes' => 5,
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
    ],
];
