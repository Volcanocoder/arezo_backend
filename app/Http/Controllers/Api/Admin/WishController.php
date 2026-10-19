<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wish;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WishController extends Controller
{
    /**
     * نمایش فهرست درخواست‌ها با امکان فیلتر وضعیت.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => [
                'sometimes',
                Rule::in(['pending', 'approved', 'rejected', 'needs_info']),
            ],
        ]);

        $query = Wish::query()->latest();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return response()->json(
            $query->paginate(15)
        );
    }

    /**
     * نمایش جزئیات کامل یک پرونده برای مدیر مجاز.
     */
    public function show(Wish $wish)
    {
        return response()->json([
            'wish' => $wish,
        ]);
    }

    /**
     * تأیید، رد یا بازگرداندن پرونده به وضعیت بررسی.
     */
    public function updateStatus(Request $request, Wish $wish)
    {
        $data = $request->validate([
            'status' => [
                'required',
                Rule::in(['pending', 'approved', 'rejected', 'needs_info']),
            ],
            'review_note' => [
                Rule::requiredIf(
                    in_array($request->input('status'), ['rejected', 'needs_info'], true)
                ),
                'nullable',
                'string',
                'max:3000',
            ],
        ]);

        $wish->status = $data['status'];

        if (array_key_exists('review_note', $data)) {
            $wish->review_note = $data['review_note'];
        } elseif ($data['status'] === 'pending') {
            $wish->review_note = null;
        }

        $wish->reviewed_at = $data['status'] === 'pending'
            ? null
            : now();

        $wish->save();

        return response()->json([
            'message' => match ($wish->status) {
                'approved' => 'درخواست با موفقیت تأیید شد.',
                'rejected' => 'درخواست رد شد.',
                'needs_info' => 'درخواست تکمیل اطلاعات برای متقاضی ثبت شد.',
                default => 'درخواست به وضعیت بررسی بازگشت.',
            },
            'wish' => $wish->fresh(),
        ]);
    }
}
