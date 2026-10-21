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
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'urgency' => ['sometimes', 'nullable', Rule::in(['normal', 'important', 'urgent'])],
            'sort' => ['sometimes', 'nullable', Rule::in(['newest', 'oldest', 'amount_desc', 'amount_asc', 'urgent'])],
        ]);

        $query = Wish::query();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['urgency'])) {
            $query->where('urgency', $filters['urgency']);
        }

        if (! empty($filters['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($filters['q'])).'%';

            $query->where(function ($sub) use ($like) {
                $sub->where('title', 'like', $like)
                    ->orWhere('applicant_name', 'like', $like)
                    ->orWhere('applicant_mobile', 'like', $like)
                    ->orWhere('city', 'like', $like);
            });
        }

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('id'),
            'amount_desc' => $query->orderByDesc('requested_amount')->orderByDesc('id'),
            'amount_asc' => $query->orderBy('requested_amount')->orderByDesc('id'),
            'urgent' => $query->orderByRaw("CASE urgency WHEN 'urgent' THEN 0 WHEN 'important' THEN 1 ELSE 2 END")->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $counts = Wish::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $page = $query->paginate(15)->withQueryString();

        return response()->json($page->toArray() + [
            'counts' => [
                'all' => (int) $counts->sum(),
                'pending' => (int) ($counts['pending'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
                'needs_info' => (int) ($counts['needs_info'] ?? 0),
            ],
        ]);
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

        $statusChanged = $wish->isDirty('status');

        $wish->save();

        // نتیجه بررسی برای صاحب آرزو اعلان می‌شود.
        if ($statusChanged && $wish->user_id && in_array($wish->status, ['approved', 'rejected', 'needs_info'], true)) {
            [$title, $body, $type] = match ($wish->status) {
                'approved' => ['آرزوی تو تأیید شد', "«{$wish->title}» تأیید شد و حالا در سایت دیده می‌شود.", 'success'],
                'rejected' => ['آرزوی تو رد شد', "«{$wish->title}» رد شد. ".($wish->review_note ?: ''), 'warning'],
                default => ['اطلاعات بیشتری لازم است', "برای «{$wish->title}» تکمیل اطلاعات لازم است. ".($wish->review_note ?: ''), 'warning'],
            };

            \App\Models\UserNotification::notify(
                $wish->user_id,
                $title,
                trim($body),
                $type,
                '/dashboard/my-wishes',
            );
        }

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
