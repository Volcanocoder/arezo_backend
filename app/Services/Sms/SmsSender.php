<?php

namespace App\Services\Sms;

interface SmsSender
{
    /**
     * ارسال کد یک‌بارمصرف به شماره موبایل.
     */
    public function sendOtp(string $mobile, string $code): void;
}
