<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function index()
    {
        $page = UserNotification::query()
            ->with('recipient:id,name,mobile')
            ->withCount('reads')
            ->latest('id')
            ->paginate(15);

        $totalUsers = User::query()->count();

        return response()->json([
            'data' => collect($page->items())->map(fn (UserNotification $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'type' => $n->type,
                'link' => $n->link,
                'audience' => $n->user_id ? 'user' : 'all',
                'recipient' => $n->recipient ? [
                    'id' => $n->recipient->id,
                    'name' => $n->recipient->name,
                    'mobile' => $n->recipient->mobile,
                ] : null,
                'reads_count' => $n->reads_count,
                'recipients_count' => $n->user_id ? 1 : $totalUsers,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->values(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(['all', 'user'])],
            'user_id' => ['required_if:audience,user', 'nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:2000'],
            'type' => ['required', Rule::in(['info', 'success', 'warning'])],
            'link' => ['nullable', 'string', 'max:255', 'regex:/^(\/(?!\/)[^\s]*|https?:\/\/[^\s]+)$/'],
        ]);

        $notification = UserNotification::create([
            'user_id' => $data['audience'] === 'user' ? $data['user_id'] : null,
            'title' => $data['title'],
            'body' => $data['body'],
            'type' => $data['type'],
            'link' => $data['link'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => $data['audience'] === 'all'
                ? 'اطلاعیه برای همه کاربران ارسال شد.'
                : 'اعلان برای کاربر ارسال شد.',
            'notification' => $notification,
        ], 201);
    }

    public function destroy(UserNotification $notification)
    {
        $notification->delete();

        return response()->json(['message' => 'اعلان حذف شد.']);
    }
}
