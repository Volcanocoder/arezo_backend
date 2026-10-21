<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function store(Request $request)
    {
        // فیلد مخفی «website» فقط توسط ربات‌ها پر می‌شود.
        if ($request->filled('website')) {
            return response()->json(['message' => 'پیام تو ارسال شد.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'contact' => ['required', 'string', 'max:150'],
            'subject' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
        ], [
            'name.required' => 'نامت را وارد کن.',
            'contact.required' => 'ایمیل یا شماره موبایلت را وارد کن.',
            'message.required' => 'پیامت را بنویس.',
            'message.min' => 'پیام باید حداقل ۱۰ نویسه باشد.',
        ]);

        ContactMessage::create($data);

        return response()->json(['message' => 'پیام تو ارسال شد. در اولین فرصت پاسخ می‌دهیم.'], 201);
    }
}
