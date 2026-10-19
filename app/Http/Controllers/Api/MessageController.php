<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatMessageFilter;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    /**
     * دریافت پیام‌های یک گفتگو؛ فقط اعضای همان گفتگو دسترسی دارند.
     */
    public function index(Request $request, Conversation $conversation)
    {
        $userId = (int) $request->user()->id;
        $conversation = $this->findAccessibleConversation($conversation->id, $userId);


        $messages = $conversation->messages()
            ->where('is_hidden', false)
            ->with('sender:id,name')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->values();

        $unreadIds = $messages
            ->filter(fn (Message $message) =>
                (int) $message->sender_id !== $userId && $message->read_at === null
            )
            ->pluck('id');

        if ($unreadIds->isNotEmpty()) {
            $conversation->messages()
                ->whereIn('id', $unreadIds)
                ->whereNull('read_at')
                ->update(['read_at' => now(), 'updated_at' => now()]);


        $messages = $conversation->messages()
            ->whereIn('id', $messages->pluck('id'))
            ->where('is_hidden', false)
            ->with('sender:id,name')
            ->orderBy('id')
            ->get();
        }

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'wish_id' => $conversation->wish_id,
                'wish_title' => $conversation->wish?->title,
                'donor_id' => $conversation->donor_id,
                'wish_owner_id' => $conversation->wish_owner_id,
            ],
            'messages' => $messages,
        ]);
    }

    /**
     * پیام را قبل از ذخیره، در سمت سرور بررسی می‌کند.
     */
    public function store(Request $request, Conversation $conversation)
    {
        $userId = (int) $request->user()->id;
        $conversation = $this->findAccessibleConversation($conversation->id, $userId);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $body = trim($validated['body']);
        if ($body === '') {
            return response()->json([
                'message' => 'متن پیام نمی‌تواند خالی باشد.',
                'code' => 'empty_message',
            ], 422);
        }

        $rejectionReason = app(ChatMessageFilter::class)->rejectionReason($body);
        if ($rejectionReason !== null) {
            return response()->json([
                'message' => $rejectionReason,
                'code' => 'chat_message_blocked',
            ], 422);
        }

        $message = $conversation->messages()->create([
            'sender_id' => $userId,
            'body' => $body,
        ]);

        $conversation->last_message_at = $message->created_at;
        $conversation->save();

        return response()->json([
            'message' => 'پیام با موفقیت ارسال شد.',
            'data' => $message->load('sender:id,name'),
        ], 201);
    }

    /** فقط یکی از دو طرف گفتگو می‌تواند آن را مشاهده یا در آن پیام ارسال کند. */
    private function findAccessibleConversation(int $conversationId, int $userId): Conversation
    {
        return Conversation::query()
            ->whereKey($conversationId)
            ->where(function ($query) use ($userId) {
                $query->where('donor_id', $userId)
                    ->orWhere('wish_owner_id', $userId);
            })
            ->with('wish:id,title')
            ->firstOrFail();
    }
}
