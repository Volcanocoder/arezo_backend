<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'mobile' => [
                'required',
                'string',
                'regex:/^09\d{9}$/',
                'unique:users,mobile',
            ],
            'password' => [
                'required',
                'confirmed',
                Password::min(8),
            ],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'mobile' => $data['mobile'],
            'password' => $data['password'],
        ]);

        // نقش پیش‌فرض دیتابیس برای ثبت‌نام عمومی user است.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'ثبت‌نام با موفقیت انجام شد.',
            'user' => $user->publicData(),
        ], 201);
    }


    public function login(Request $request)
    {
        $data = $request->validate([
            'mobile' => [
                'required',
                'string',
                'regex:/^09\d{9}$/',
            ],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('mobile', $data['mobile'])
            ->first();

        if (
            ! $user ||
            ! Hash::check($data['password'], $user->password)
        ) {
            return response()->json([
                'message' => 'شماره موبایل یا رمز عبور صحیح نیست.',
            ], 422);
        }

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'حساب شما تعلیق شده است. برای اطلاعات بیشتر با پشتیبانی تماس بگیرید.',
            ], 403);
        }

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'message' => 'ورود با موفقیت انجام شد.',
            'user' => $user->publicData(),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user()->publicData(),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'با موفقیت از حساب خارج شدی.',
        ]);
    }
}
