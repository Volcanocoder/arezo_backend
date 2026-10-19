<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Wish;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /**
     * نمایش گفتگوهای کاربر واردشده
     */
    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;

        $conversations = Conversation::query()
            ->where(function ($query) use ($userId) {
                $query->where('donor_id', $userId)
                    ->orWhere('wish_owner_id', $userId);
            })
            ->with([
                'wish:id,title,category,city,requested_amount,status,created_at',
                'donor:id,name',
                'wishOwner:id,name',
            ])
            ->withCount([
                'messages as unread_messages_count' => function ($query) use ($userId) {
                    $query->whereNull('read_at')
                        ->where('sender_id', '!=', $userId);
                },
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($conversations);
    }

    /**
     * شروع گفتگوی جدید یا برگرداندن گفتگوی قبلی
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'wish_id' => ['required', 'integer', 'exists:wishes,id'],
        ]);

        $user = $request->user();

        // فقط آرزوهای تأییدشده امکان شروع گفتگو دارند.
        $wish = Wish::query()
            ->where('status', 'approved')
            ->find($validated['wish_id']);

        if (!$wish) {
            return response()->json([
                'message' => 'این آرزو وجود ندارد یا هنوز تأیید نشده است.',
            ], 404);
        }

        // آرزوی قدیمی که صاحب حساب مشخصی ندارد.
        if (!$wish->user_id) {
            return response()->json([
                'message' => 'برای این آرزو امکان شروع گفتگو وجود ندارد.',
            ], 422);
        }

        // کاربر نمی‌تواند برای آرزوی خودش گفتگوی کمک‌کننده بسازد.
        if ((int) $wish->user_id === (int) $user->id) {
            return response()->json([
                'message' => 'نمی‌توانی برای آرزوی خودت گفتگو شروع کنی.',
            ], 403);
        }

        // برای هر کمک‌کننده و هر آرزو فقط یک گفتگوی اصلی داریم.
        $conversation = Conversation::firstOrCreate(
            [
                'wish_id' => $wish->id,
                'donor_id' => $user->id,
            ],
            [
                'wish_owner_id' => $wish->user_id,
            ]
        );

        $conversation->load([
            'wish:id,title,category,city,requested_amount,status,created_at',
            'donor:id,name',
            'wishOwner:id,name',
        ]);

        return response()->json([
            'message' => $conversation->wasRecentlyCreated
                ? 'گفتگو با موفقیت ایجاد شد.'
                : 'گفتگوی قبلی پیدا شد.',
            'conversation' => $conversation,
        ], $conversation->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * مشاهده اطلاعات یک گفتگو؛ فقط برای طرفین گفتگو
     */
    public function show(Request $request, Conversation $conversation)
    {
        $userId = (int) $request->user()->id;

        $authorizedConversation = Conversation::query()
            ->whereKey($conversation->id)
            ->where(function ($query) use ($userId) {
                $query->where('donor_id', $userId)
                    ->orWhere('wish_owner_id', $userId);
            })
            ->with([
                'wish:id,title,category,city,requested_amount,status,created_at',
                'donor:id,name',
                'wishOwner:id,name',
            ])
            ->withCount([
                'messages as unread_messages_count' => function ($query) use ($userId) {
                    $query->whereNull('read_at')
                        ->where('sender_id', '!=', $userId);
                },
            ])
            ->firstOrFail();

        return response()->json([
            'conversation' => $authorizedConversation,
        ]);
    }
}