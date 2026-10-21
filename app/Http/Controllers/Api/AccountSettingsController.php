<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountSettingsController extends Controller
{
    /**
     * دریافت اطلاعات حساب کاربر واردشده
     */
    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => $this->userData($user),
        ]);
    }

    /**
     * به‌روزرسانی اطلاعات شخصی
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => [
                'required',
                'regex:/^09\d{9}$/',
                Rule::unique('users', 'mobile')->ignore($user->id),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'city' => ['nullable', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:250'],
        ], [
            'name.required' => 'نام و نام خانوادگی را وارد کن.',
            'mobile.required' => 'شماره موبایل را وارد کن.',
            'mobile.regex' => 'شماره موبایل باید ۱۱ رقم و با 09 شروع شود.',
            'mobile.unique' => 'این شماره موبایل قبلاً ثبت شده است.',
            'email.email' => 'فرمت ایمیل معتبر نیست.',
            'email.unique' => 'این ایمیل قبلاً ثبت شده است.',
        ]);

        $user->fill($validated);
        $user->save();

        return response()->json([
            'message' => 'اطلاعات حساب با موفقیت ذخیره شد.',
            'user' => $this->userData($user->fresh()),
        ]);
    }

    /**
     * ذخیره ترجیحات اعلان‌ها
     */
    public function updatePreferences(Request $request)
    {
        $validated = $request->validate([
            'wish_updates' => ['required', 'boolean'],
            'new_messages' => ['required', 'boolean'],
            'wish_completed' => ['required', 'boolean'],
            'email_updates' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $user->fill($validated);
        $user->save();

        return response()->json([
            'message' => 'تنظیمات اعلان‌ها ذخیره شد.',
            'preferences' => [
                'wish_updates' => (bool) $user->wish_updates,
                'new_messages' => (bool) $user->new_messages,
                'wish_completed' => (bool) $user->wish_completed,
                'email_updates' => (bool) $user->email_updates,
            ],
        ]);
    }

    /**
     * تغییر رمز عبور با بررسی رمز فعلی
     */
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'current_password.required' => 'رمز عبور فعلی را وارد کن.',
            'new_password.required' => 'رمز عبور جدید را وارد کن.',
            'new_password.min' => 'رمز عبور جدید باید حداقل ۸ کاراکتر باشد.',
            'new_password.confirmed' => 'تکرار رمز عبور جدید مطابقت ندارد.',
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'رمز عبور فعلی صحیح نیست.',
            ], 422);
        }

        if (Hash::check($validated['new_password'], $user->password)) {
            return response()->json([
                'message' => 'رمز عبور جدید باید با رمز فعلی متفاوت باشد.',
            ], 422);
        }

        // مدل User با cast نوع hashed رمز را به‌صورت امن هش می‌کند.
        $user->password = $validated['new_password'];
        $user->save();

        return response()->json([
            'message' => 'رمز عبور با موفقیت تغییر کرد.',
        ]);
    }

    /**
     * آپلود یا جایگزینی تصویر پروفایل
     */
    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=6000,max_height=6000'],
        ], [
            'avatar.required' => 'یک تصویر انتخاب کن.',
            'avatar.image' => 'فایل انتخاب‌شده تصویر نیست.',
            'avatar.mimes' => 'فقط تصویر JPG، PNG یا WEBP قابل قبول است.',
            'avatar.max' => 'حجم تصویر باید کمتر از ۲ مگابایت باشد.',
            'avatar.dimensions' => 'ابعاد تصویر مناسب نیست (حداقل ۶۴ پیکسل).',
        ]);

        $user = $request->user();
        $old = $user->avatar_path;

        // نام فایل تصادفی است و نام اصلی کاربر هرگز استفاده نمی‌شود.
        $path = $request->file('avatar')->storeAs(
            'avatars',
            Str::random(40).'.'.$request->file('avatar')->guessExtension(),
            'local',
        );

        $user->avatar_path = $path;
        $user->save();

        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return response()->json([
            'message' => 'تصویر پروفایل ذخیره شد.',
            'avatar_url' => $user->avatarUrl(),
        ]);
    }

    /**
     * حذف تصویر پروفایل
     */
    public function deleteAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('local')->delete($user->avatar_path);
            $user->avatar_path = null;
            $user->save();
        }

        return response()->json(['message' => 'تصویر پروفایل حذف شد.', 'avatar_url' => null]);
    }

    /**
     * اطلاعات مجاز برای نمایش در فرانت‌اند
     */
    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'mobile' => $user->mobile,
            'email' => $user->email,
            'city' => $user->city,
            'bio' => $user->bio,
            'avatar_url' => $user->avatarUrl(),
            'preferences' => [
                'wish_updates' => (bool) $user->wish_updates,
                'new_messages' => (bool) $user->new_messages,
                'wish_completed' => (bool) $user->wish_completed,
                'email_updates' => (bool) $user->email_updates,
            ],
        ];
    }
}