<?php

namespace App\Services;

use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\DB;

class OtpService
{
    public function __construct(private SmsSender $sms)
    {
    }

    /**
     * کد جدید می‌سازد و می‌فرستد. اگر هنوز در بازه انتظار هستیم، چیزی نمی‌فرستد.
     * نتیجه true یعنی پیامک ارسال شد.
     */
    public function issue(string $mobile, string $purpose): bool
    {
        $cooldown = (int) config('sms.otp.resend_cooldown_seconds');

        $recent = DB::table('otp_codes')
            ->where('mobile', $mobile)
            ->where('purpose', $purpose)
            ->where('created_at', '>', now()->subSeconds($cooldown))
            ->exists();

        if ($recent) {
            return false;
        }

        // کدهای قبلی باطل می‌شوند.
        DB::table('otp_codes')
            ->where('mobile', $mobile)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $length = (int) config('sms.otp.length');
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        DB::table('otp_codes')->insert([
            'mobile' => $mobile,
            'purpose' => $purpose,
            'code_hash' => $this->hash($mobile, $purpose, $code),
            'expires_at' => now()->addMinutes((int) config('sms.otp.ttl_minutes')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->sms->sendOtp($mobile, $code);

        return true;
    }

    /**
     * کد را بررسی می‌کند؛ در صورت درست بودن آن را مصرف می‌کند.
     */
    public function verify(string $mobile, string $purpose, string $code): bool
    {
        return DB::transaction(function () use ($mobile, $purpose, $code) {
            $otp = DB::table('otp_codes')
                ->where('mobile', $mobile)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otp) {
                return false;
            }

            if ($otp->attempts >= (int) config('sms.otp.max_attempts')) {
                return false;
            }

            if (! hash_equals($otp->code_hash, $this->hash($mobile, $purpose, $code))) {
                DB::table('otp_codes')->where('id', $otp->id)->increment('attempts');

                return false;
            }

            DB::table('otp_codes')->where('id', $otp->id)->update([
                'consumed_at' => now(),
            ]);

            return true;
        });
    }

    private function hash(string $mobile, string $purpose, string $code): string
    {
        return hash_hmac('sha256', "{$mobile}|{$purpose}|{$code}", (string) config('app.key'));
    }
}
