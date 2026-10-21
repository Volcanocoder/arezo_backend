<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class PasswordResetController extends Controller
{
    private const PURPOSE = 'password_reset';

    /**
     * ارسال کد بازیابی. برای جلوگیری از افشای وجود حساب، پاسخ همیشه یکسان است.
     */
    public function forgot(Request $request, OtpService $otp)
    {
        $data = $request->validate([
            'mobile' => ['required', 'string', 'regex:/^09\d{9}$/'],
        ]);

        if (User::query()->where('mobile', $data['mobile'])->exists()) {
            $otp->issue($data['mobile'], self::PURPOSE);
        }

        return response()->json([
            'message' => 'اگر این شماره در آرزو ثبت شده باشد، کد بازیابی برایش پیامک می‌شود.',
        ]);
    }

    public function reset(Request $request, OtpService $otp)
    {
        $data = $request->validate([
            'mobile' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::query()->where('mobile', $data['mobile'])->first();

        // کد باید حتماً بررسی شود تا زمان پاسخ و پیام برای شماره‌های نامعتبر یکسان بماند.
        $valid = $otp->verify($data['mobile'], self::PURPOSE, $data['code']);

        if (! $user || ! $valid) {
            return response()->json([
                'message' => 'کد واردشده نادرست یا منقضی است.',
            ], 422);
        }

        $user->password = $data['password'];
        $user->setRememberToken(null);
        $user->save();

        // همه نشست‌های فعال این کاربر بسته می‌شوند.
        DB::table('sessions')->where('user_id', $user->id)->delete();

        return response()->json([
            'message' => 'رمز عبور تغییر کرد. حالا می‌توانی وارد شوی.',
        ]);
    }
}
